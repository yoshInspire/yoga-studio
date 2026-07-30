@php
    /** @var \App\Models\AsanaProgramItem|null $drawingItem */
    $isItem = $drawingMode === 'item' && ($drawingItem ?? null) !== null;
    $baseUrl = $isItem ? $drawingItem->imageUrl() : null;
@endphp

<div
    class="as-draw"
    wire:key="draw-{{ $drawingMode }}-{{ $drawingItemId ?? 'new' }}"
    x-data="asanaCanvas({
        baseUrl: @js($baseUrl),
        isItem: @js($isItem),
        itemId: @js($drawingItemId),
    })"
    x-on:keydown.escape.window="close()"
>
    <div class="as-draw__backdrop" @click="close()"></div>

    <div class="as-draw__sheet" role="dialog" aria-modal="true" aria-label="Зарисовка позы">
        <div class="as-draw__grabber" aria-hidden="true"></div>

        <header class="as-draw__head">
            <div>
                <strong class="as-draw__title">{{ $isItem ? 'Правка позы' : 'Новая поза' }}</strong>
                <p class="as-draw__sub">
                    {{ $isItem
                        ? 'Дорисуйте или подпишите — в базе поза останется прежней'
                        : 'Нарисуйте человечка — он попадёт в вашу библиотеку' }}
                </p>
            </div>
            <button type="button" class="as-icon-btn" title="Закрыть" @click="close()">
                @include('filament.pages.asanas._icon', ['name' => 'close'])
            </button>
        </header>

        {{-- wire:ignore обязателен: перерисовка Livewire стёрла бы холст --}}
        <div class="as-draw__canvas-wrap" wire:ignore>
            <canvas
                x-ref="canvas"
                class="as-draw__canvas"
                x-on:pointerdown.prevent="start($event)"
                x-on:pointermove.prevent="move($event)"
                x-on:pointerup.prevent="end($event)"
                x-on:pointercancel.prevent="end($event)"
                x-on:pointerleave.prevent="end($event)"
            ></canvas>
        </div>

        <div class="as-draw__tools">
            <div class="as-seg" role="group" aria-label="Инструмент">
                <button type="button" class="as-seg__btn" x-bind:class="tool === 'pen' && 'as-seg__btn--on'" @click="tool = 'pen'">
                    @include('filament.pages.asanas._icon', ['name' => 'pencil'])
                    <span>Перо</span>
                </button>
                <button type="button" class="as-seg__btn" x-bind:class="tool === 'eraser' && 'as-seg__btn--on'" @click="tool = 'eraser'">
                    @include('filament.pages.asanas._icon', ['name' => 'eraser'])
                    <span>Ластик</span>
                </button>
            </div>

            <div class="as-seg" role="group" aria-label="Толщина линии">
                <template x-for="w in widths" :key="w">
                    <button type="button" class="as-seg__btn as-seg__btn--w"
                            x-bind:class="width === w && 'as-seg__btn--on'" @click="width = w">
                        <span class="as-dot" x-bind:style="`width:${w * 2 + 2}px;height:${w * 2 + 2}px`"></span>
                    </button>
                </template>
            </div>

            <div class="as-seg">
                <button type="button" class="as-seg__btn" @click="undo()" x-bind:disabled="! strokes.length" title="Отменить штрих">
                    @include('filament.pages.asanas._icon', ['name' => 'undo'])
                </button>
                <button type="button" class="as-seg__btn" @click="clear()" title="Очистить">
                    @include('filament.pages.asanas._icon', ['name' => 'trash'])
                </button>
            </div>
        </div>

        <template x-if="! isItem">
            <input
                type="text"
                class="as-input"
                placeholder="Название позы (необязательно)"
                x-model="name"
                aria-label="Название позы"
            />
        </template>

        <footer class="as-draw__foot">
            <button type="button" class="as-btn as-btn--ghost" @click="close()">Отмена</button>
            <button type="button" class="as-btn as-btn--primary" @click="save()" x-bind:disabled="saving">
                <span x-text="saving ? 'Сохраняю…' : 'Сохранить'"></span>
            </button>
        </footer>
    </div>
</div>

@script
<script>
    Alpine.data('asanaCanvas', (config) => ({
        // Холст крупнее исходной картинки: подписи стилусом не выглядят грубыми.
        W: 640,
        H: 480,

        isItem: config.isItem,
        itemId: config.itemId,
        baseUrl: config.baseUrl,

        tool: 'pen',
        width: 3,
        widths: [2, 3, 6, 12],
        name: '',
        saving: false,

        strokes: [],
        current: null,
        base: null,

        init() {
            const canvas = this.$refs.canvas;
            const ratio = Math.min(window.devicePixelRatio || 1, 3);

            canvas.width = this.W * ratio;
            canvas.height = this.H * ratio;

            this.ctx = canvas.getContext('2d');
            this.ctx.scale(ratio, ratio);
            this.ctx.lineCap = 'round';
            this.ctx.lineJoin = 'round';

            if (this.baseUrl) {
                const img = new Image();
                img.onload = () => {
                    this.base = img;
                    this.redraw();
                };
                // Ссылка от корня сайта — тот же origin, холст не «портится».
                img.src = this.baseUrl;
            }

            this.redraw();
        },

        point(event) {
            const rect = this.$refs.canvas.getBoundingClientRect();

            return {
                x: (event.clientX - rect.left) * (this.W / rect.width),
                y: (event.clientY - rect.top) * (this.H / rect.height),
            };
        },

        start(event) {
            this.current = {
                tool: this.tool,
                width: this.tool === 'eraser' ? this.width * 4 : this.width,
                points: [this.point(event)],
            };

            // Захват удерживает рисование, если палец ушёл за край холста.
            // Это удобство, а не необходимость: если браузер откажет, штрих
            // всё равно должен начаться, поэтому ошибку глотаем.
            try {
                this.$refs.canvas.setPointerCapture?.(event.pointerId);
            } catch (e) {
                // без захвата рисуем как есть
            }
        },

        move(event) {
            if (! this.current) {
                return;
            }

            this.current.points.push(this.point(event));
            this.redraw();
        },

        end(event) {
            if (! this.current) {
                return;
            }

            try {
                this.$refs.canvas.releasePointerCapture?.(event.pointerId);
            } catch (e) {
                // захвата могло и не быть
            }

            // Одиночное касание тоже должно оставлять точку.
            if (this.current.points.length === 1) {
                this.current.points.push({ ...this.current.points[0] });
            }

            this.strokes.push(this.current);
            this.current = null;
            this.redraw();
        },

        undo() {
            this.strokes.pop();
            this.redraw();
        },

        clear() {
            this.strokes = [];
            this.current = null;
            this.redraw();
        },

        redraw() {
            const ctx = this.ctx;

            ctx.clearRect(0, 0, this.W, this.H);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, this.W, this.H);

            if (this.base) {
                const scale = Math.min(this.W / this.base.width, this.H / this.base.height);
                const w = this.base.width * scale;
                const h = this.base.height * scale;
                ctx.drawImage(this.base, (this.W - w) / 2, (this.H - h) / 2, w, h);
            }

            const all = this.current ? [...this.strokes, this.current] : this.strokes;

            for (const stroke of all) {
                ctx.beginPath();
                ctx.lineWidth = stroke.width;
                // Ластик стирает до белого фона, а не до прозрачности.
                ctx.strokeStyle = stroke.tool === 'eraser' ? '#ffffff' : '#111827';

                stroke.points.forEach((p, i) => {
                    i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y);
                });

                ctx.stroke();
            }
        },

        async save() {
            if (this.saving) {
                return;
            }

            if (! this.isItem && ! this.strokes.length) {
                window.alert('Нарисуйте позу перед сохранением.');

                return;
            }

            this.saving = true;
            const dataUrl = this.$refs.canvas.toDataURL('image/png');

            try {
                if (this.isItem) {
                    await this.$wire.saveItemDrawing(this.itemId, dataUrl);
                } else {
                    await this.$wire.saveNewDrawing(dataUrl, this.name);
                }
            } finally {
                this.saving = false;
            }
        },

        close() {
            this.$wire.stopDrawing();
        },
    }));
</script>
@endscript
