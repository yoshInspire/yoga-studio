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

        {{-- Для новой позы: название и раздел библиотеки --}}
        @if (! $isItem)
            <input
                type="text"
                class="as-input"
                placeholder="Название позы (необязательно)"
                x-model="name"
                aria-label="Название позы"
            />

            <label class="as-field">
                <span class="as-field__label">Положить в раздел</span>
                <select class="as-input as-select" x-model="category" aria-label="Раздел библиотеки">
                    <option value="">Мои зарисовки</option>
                    @foreach ($libraryCategories ?? [] as $category)
                        <option value="{{ $category }}">{{ $category }}</option>
                    @endforeach
                </select>
            </label>
        @endif

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
        category: '',
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

        /**
         * Обрезать пустые поля вокруг рисунка и отдать картинку в тех же
         * пропорциях, что и готовые асаны (4:3).
         *
         * Без этого своя зарисовка уезжала в PDF «в мини-формате»: фигурка
         * занимает часть холста, а белые поля вокруг едут в файл и при печати
         * съедают место, поэтому рядом с библиотечной позой она выглядит мелкой.
         */
        exportImage() {
            const canvas = this.$refs.canvas;
            const dpr = canvas.width / this.W;
            // Контекст берём у самого холста: getContext возвращает тот же
            // объект, и это не зависит от того, как вызван метод.
            const pixels = canvas.getContext('2d')
                .getImageData(0, 0, canvas.width, canvas.height).data;

            let minX = canvas.width, minY = canvas.height, maxX = -1, maxY = -1;

            for (let y = 0; y < canvas.height; y++) {
                for (let x = 0; x < canvas.width; x++) {
                    const i = (y * canvas.width + x) * 4;

                    // Фон заливается белым, поэтому содержимое — всё заметно темнее.
                    if (pixels[i] < 240 || pixels[i + 1] < 240 || pixels[i + 2] < 240) {
                        if (x < minX) minX = x;
                        if (x > maxX) maxX = x;
                        if (y < minY) minY = y;
                        if (y > maxY) maxY = y;
                    }
                }
            }

            // Холст пуст — отдаём как есть.
            if (maxX < 0) {
                return canvas.toDataURL('image/png');
            }

            const pad = Math.round(10 * dpr);
            minX = Math.max(0, minX - pad);
            minY = Math.max(0, minY - pad);
            maxX = Math.min(canvas.width - 1, maxX + pad);
            maxY = Math.min(canvas.height - 1, maxY + pad);

            let w = maxX - minX + 1;
            let h = maxY - minY + 1;
            const cx = minX + w / 2;
            const cy = minY + h / 2;

            // Дотягиваем до 4:3, расширяя меньшую сторону от центра рисунка.
            const target = this.W / this.H;
            if (w / h < target) {
                w = h * target;
            } else {
                h = w / target;
            }

            const out = document.createElement('canvas');
            out.width = 600;
            out.height = Math.round(600 / target);

            const octx = out.getContext('2d');
            octx.fillStyle = '#ffffff';
            octx.fillRect(0, 0, out.width, out.height);
            octx.drawImage(canvas, cx - w / 2, cy - h / 2, w, h, 0, 0, out.width, out.height);

            return out.toDataURL('image/png');
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
            const dataUrl = this.exportImage();

            try {
                if (this.isItem) {
                    await this.$wire.saveItemDrawing(this.itemId, dataUrl);
                } else {
                    await this.$wire.saveNewDrawing(dataUrl, this.name, this.category);
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
