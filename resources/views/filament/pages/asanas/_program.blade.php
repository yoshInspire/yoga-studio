@php
    /** @var \App\Models\AsanaProgram|null $program */
    /** @var \Illuminate\Support\Collection $items */
@endphp

@if ($program === null)
    <p class="as-empty">Занятие не найдено.</p>
@else
    <div wire:key="program-view-{{ $program->id }}">
        {{-- Шапка --}}
        <nav class="as-crumbs" aria-label="Путь по папкам">
            <button type="button" class="as-crumb" wire:click="openFolder(null)">Все папки</button>
            @foreach ($breadcrumbs as $crumb)
                <span class="as-crumb__sep" aria-hidden="true">›</span>
                <button type="button" class="as-crumb" wire:click="openFolder({{ $crumb->id }})">{{ $crumb->name }}</button>
            @endforeach
            <span class="as-crumb__sep" aria-hidden="true">›</span>
            <span class="as-crumb as-crumb--current">{{ $program->title }}</span>
        </nav>

        <div class="as-head">
            <h2 class="as-head__title">{{ $program->title }}</h2>
            <button
                type="button"
                class="as-icon-btn"
                title="Переименовать занятие"
                @click="
                    const title = window.prompt('Название занятия', @js($program->title));
                    if (title && title.trim()) $wire.renameProgram({{ $program->id }}, title);
                "
            >✎</button>
        </div>

        <div class="as-note" x-data="{ note: @js($program->note ?? ''), saved: false }">
            <textarea
                class="as-input as-input--area"
                rows="2"
                placeholder="Заметка к занятию — для кого, на что обратить внимание"
                x-model="note"
                @blur="$wire.saveProgramNote(note)"
                aria-label="Заметка к занятию"
            ></textarea>
        </div>

        {{-- Действия --}}
        <div class="as-actions">
            <button type="button" class="as-btn as-btn--primary" wire:click="openLibrary">
                + Добавить позу
            </button>
            <button type="button" class="as-btn as-btn--ghost" wire:click="startNewDrawing">
                ✎ Зарисовать свою
            </button>
            @if ($items->isNotEmpty())
                <button type="button" class="as-btn as-btn--ghost" onclick="window.print()">
                    ⎙ Печать / PDF
                </button>
            @endif
        </div>

        {{-- Последовательность поз --}}
        @if ($items->isEmpty())
            <p class="as-empty">
                Поз пока нет. Нажмите «Добавить позу», чтобы взять из базы, или «Зарисовать свою».
            </p>
        @else
            <div
                class="as-seq"
                x-sortable
                x-on:end.stop="$wire.reorderItems($event.target.sortable.toArray())"
                data-sortable-animation-duration="150"
            >
                @foreach ($items as $index => $item)
                    <article
                        class="as-pose"
                        wire:key="item-{{ $item->id }}"
                        x-sortable-item="{{ $item->id }}"
                    >
                        <div class="as-pose__num" x-sortable-handle title="Перетащите, чтобы поменять порядок">
                            {{ $index + 1 }}
                            <span class="as-pose__grip" aria-hidden="true">⠿</span>
                        </div>

                        <div class="as-pose__img">
                            @if ($item->imageUrl())
                                <img src="{{ $item->imageUrl() }}" alt="{{ $item->title() }}" loading="lazy" />
                            @else
                                <span class="as-pose__noimg">нет картинки</span>
                            @endif
                        </div>

                        <div class="as-pose__body">
                            <h3 class="as-pose__name">
                                {{ $item->title() }}
                                @if ($item->isEdited())
                                    <span class="as-tag">с правкой</span>
                                @endif
                            </h3>

                            <input
                                type="text"
                                class="as-input as-input--sm"
                                placeholder="Подпись: счёт, дыхание, акцент"
                                value="{{ $item->note }}"
                                @blur="$wire.saveItemNote({{ $item->id }}, $event.target.value)"
                                aria-label="Подпись к позе"
                            />

                            <div class="as-pose__tools">
                                <button
                                    type="button"
                                    class="as-icon-btn"
                                    title="Вверх"
                                    wire:click="moveItem({{ $item->id }}, -1)"
                                    @disabled($loop->first)
                                >↑</button>
                                <button
                                    type="button"
                                    class="as-icon-btn"
                                    title="Вниз"
                                    wire:click="moveItem({{ $item->id }}, 1)"
                                    @disabled($loop->last)
                                >↓</button>
                                <button
                                    type="button"
                                    class="as-icon-btn"
                                    title="Подписать или дорисовать стилусом"
                                    wire:click="startItemDrawing({{ $item->id }})"
                                >✎</button>
                                @if ($item->isEdited())
                                    <button
                                        type="button"
                                        class="as-icon-btn"
                                        title="Вернуть исходную позу"
                                        wire:click="resetItemDrawing({{ $item->id }})"
                                    >↺</button>
                                @endif
                                <button
                                    type="button"
                                    class="as-icon-btn as-icon-btn--danger"
                                    title="Убрать позу"
                                    wire:click="removeItem({{ $item->id }})"
                                >🗑</button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            {{-- Версия для печати: только позы, крупной сеткой --}}
            <div class="as-print" aria-hidden="true">
                <h1 class="as-print__title">{{ $program->title }}</h1>
                @if (filled($program->note))
                    <p class="as-print__note">{{ $program->note }}</p>
                @endif
                <div class="as-print__grid">
                    @foreach ($items as $index => $item)
                        <figure class="as-print__cell">
                            @if ($item->imageUrl())
                                <img src="{{ $item->imageUrl() }}" alt="" />
                            @endif
                            <figcaption>
                                <strong>{{ $index + 1 }}.</strong> {{ $item->title() }}
                                @if (filled($item->note))
                                    <span>— {{ $item->note }}</span>
                                @endif
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif
