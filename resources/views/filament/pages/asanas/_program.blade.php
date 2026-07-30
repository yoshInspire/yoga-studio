@php
    /** @var \App\Models\AsanaProgram|null $program */
    /** @var \Illuminate\Support\Collection $items */
@endphp

@if ($program === null)
    <div class="as-empty">
        <p class="as-empty__title">Занятие не найдено</p>
        <button type="button" class="as-btn as-btn--ghost" wire:click="openFolder(null)">К списку папок</button>
    </div>
@else
    <div wire:key="program-view-{{ $program->id }}">
        <nav class="as-crumbs" aria-label="Путь по папкам">
            <button type="button" class="as-crumb" wire:click="openFolder(null)">Все папки</button>
            @foreach ($breadcrumbs as $crumb)
                @include('filament.pages.asanas._icon', ['name' => 'chevron', 'class' => 'as-crumb__sep'])
                <button type="button" class="as-crumb" wire:click="openFolder({{ $crumb->id }})">{{ $crumb->name }}</button>
            @endforeach
            @include('filament.pages.asanas._icon', ['name' => 'chevron', 'class' => 'as-crumb__sep'])
            <span class="as-crumb as-crumb--current">{{ $program->title }}</span>
        </nav>

        {{-- Шапка занятия --}}
        <section class="as-panel">
            <div class="as-panel__head">
                <span class="as-avatar as-avatar--program">
                    @include('filament.pages.asanas._icon', ['name' => 'sparkles'])
                </span>
                <div class="as-panel__titles">
                    <h2 class="as-panel__title">{{ $program->title }}</h2>
                    <p class="as-panel__meta">
                        {{ $items->count() }} {{ \App\Support\RussianPlural::poses($items->count()) }}
                    </p>
                </div>
                <button
                    type="button"
                    class="as-icon-btn"
                    title="Переименовать занятие"
                    @click="
                        const title = window.prompt('Название занятия', @js($program->title));
                        if (title && title.trim()) $wire.renameProgram({{ $program->id }}, title);
                    "
                >@include('filament.pages.asanas._icon', ['name' => 'pencil'])</button>
            </div>

            <textarea
                class="as-input as-input--area"
                rows="2"
                placeholder="Заметка к занятию — для кого, на что обратить внимание"
                @blur="$wire.saveProgramNote($event.target.value)"
                aria-label="Заметка к занятию"
            >{{ $program->note }}</textarea>
        </section>

        {{-- Последовательность поз --}}
        @if ($items->isEmpty())
            <div class="as-empty">
                <span class="as-empty__icon">
                    @include('filament.pages.asanas._icon', ['name' => 'image'])
                </span>
                <p class="as-empty__title">Поз пока нет</p>
                <p class="as-empty__text">
                    Возьмите готовые из базы или зарисуйте свою — последовательность
                    можно будет менять перетаскиванием.
                </p>
            </div>
        @else
            <div
                class="as-seq"
                x-sortable
                x-on:end.stop="$wire.reorderItems($event.target.sortable.toArray())"
                data-sortable-animation-duration="150"
            >
                @foreach ($items as $index => $item)
                    <article class="as-pose" wire:key="item-{{ $item->id }}" x-sortable-item="{{ $item->id }}">
                        <div class="as-pose__handle" x-sortable-handle title="Перетащите, чтобы поменять порядок">
                            <span class="as-pose__num">{{ $index + 1 }}</span>
                            @include('filament.pages.asanas._icon', ['name' => 'grip', 'class' => 'as-pose__grip'])
                        </div>

                        <div class="as-pose__img">
                            @if ($item->imageUrl())
                                <img src="{{ $item->imageUrl() }}" alt="{{ $item->title() }}" loading="lazy" />
                            @else
                                @include('filament.pages.asanas._icon', ['name' => 'image', 'class' => 'as-pose__noimg'])
                            @endif
                        </div>

                        <div class="as-pose__body">
                            <h3 class="as-pose__name">
                                {{ $item->title() }}
                                @if ($item->isEdited())
                                    <span class="as-tag">правка</span>
                                @endif
                            </h3>

                            <input
                                type="text"
                                class="as-input as-input--sm"
                                placeholder="Счёт, дыхание, акцент"
                                value="{{ $item->note }}"
                                @blur="$wire.saveItemNote({{ $item->id }}, $event.target.value)"
                                aria-label="Подпись к позе"
                            />

                            <div class="as-pose__tools">
                                <button type="button" class="as-icon-btn" title="Вверх"
                                        wire:click="moveItem({{ $item->id }}, -1)" @disabled($loop->first)>
                                    @include('filament.pages.asanas._icon', ['name' => 'up'])
                                </button>
                                <button type="button" class="as-icon-btn" title="Вниз"
                                        wire:click="moveItem({{ $item->id }}, 1)" @disabled($loop->last)>
                                    @include('filament.pages.asanas._icon', ['name' => 'down'])
                                </button>
                                <button type="button" class="as-icon-btn" title="Подписать или дорисовать"
                                        wire:click="startItemDrawing({{ $item->id }})">
                                    @include('filament.pages.asanas._icon', ['name' => 'pencil'])
                                </button>
                                @if ($item->isEdited())
                                    <button type="button" class="as-icon-btn" title="Вернуть исходную позу"
                                            wire:click="resetItemDrawing({{ $item->id }})">
                                        @include('filament.pages.asanas._icon', ['name' => 'reset'])
                                    </button>
                                @endif
                                <button type="button" class="as-icon-btn as-icon-btn--danger" title="Убрать позу"
                                        wire:click="removeItem({{ $item->id }})">
                                    @include('filament.pages.asanas._icon', ['name' => 'trash'])
                                </button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            {{-- Версия для печати --}}
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

    {{-- Панель действий: на телефоне прилипает к низу и всегда под рукой --}}
    <div class="as-bar">
        <button type="button" class="as-btn as-btn--primary" wire:click="openLibrary">
            @include('filament.pages.asanas._icon', ['name' => 'plus'])
            Добавить позу
        </button>
        <button type="button" class="as-btn as-btn--ghost" wire:click="startNewDrawing">
            @include('filament.pages.asanas._icon', ['name' => 'pencil'])
            Зарисовать
        </button>
        @if ($items->isNotEmpty())
            <button type="button" class="as-btn as-btn--ghost as-bar__print" onclick="window.print()" title="Печать или PDF">
                @include('filament.pages.asanas._icon', ['name' => 'printer'])
                <span class="as-bar__label">PDF</span>
            </button>
        @endif
    </div>
@endif
