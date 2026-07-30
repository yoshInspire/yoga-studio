@php
    /** @var \Illuminate\Support\Collection $folders */
    /** @var \Illuminate\Support\Collection $programs */
@endphp

<div wire:key="folders-{{ $folderId ?? 'root' }}" x-data="{ adding: null }">
    {{-- Путь по папкам --}}
    <nav class="as-crumbs" aria-label="Путь по папкам">
        <button type="button" class="as-crumb" wire:click="openFolder(null)">Все папки</button>
        @foreach ($breadcrumbs as $crumb)
            @include('filament.pages.asanas._icon', ['name' => 'chevron', 'class' => 'as-crumb__sep'])
            @if ($loop->last)
                <span class="as-crumb as-crumb--current">{{ $crumb->name }}</span>
            @else
                <button type="button" class="as-crumb" wire:click="openFolder({{ $crumb->id }})">{{ $crumb->name }}</button>
            @endif
        @endforeach
    </nav>

    {{-- Добавление: кнопки раскрывают поле, чтобы не занимать экран телефона --}}
    <div class="as-add">
        <div class="as-add__buttons">
            <button
                type="button"
                class="as-btn as-btn--ghost"
                x-bind:class="adding === 'folder' && 'as-btn--active'"
                @click="adding = adding === 'folder' ? null : 'folder'; $nextTick(() => $refs.folderInput?.focus())"
            >
                @include('filament.pages.asanas._icon', ['name' => 'folder'])
                Папка
            </button>
            <button
                type="button"
                class="as-btn as-btn--primary"
                @click="adding = adding === 'program' ? null : 'program'; $nextTick(() => $refs.programInput?.focus())"
            >
                @include('filament.pages.asanas._icon', ['name' => 'plus'])
                Занятие
            </button>
        </div>

        <div class="as-add__field" x-show="adding === 'folder'" x-cloak x-transition.opacity>
            <input
                type="text"
                class="as-input"
                x-ref="folderInput"
                placeholder="Название папки — например, Растяжка"
                wire:model="newFolderName"
                wire:keydown.enter.prevent="createFolder"
                aria-label="Название новой папки"
            />
            <button type="button" class="as-btn as-btn--ghost" wire:click="createFolder">Создать</button>
        </div>

        <div class="as-add__field" x-show="adding === 'program'" x-cloak x-transition.opacity>
            <input
                type="text"
                class="as-input"
                x-ref="programInput"
                placeholder="Название занятия — например, Шпагаты"
                wire:model="newProgramTitle"
                wire:keydown.enter.prevent="createProgram"
                aria-label="Название нового занятия"
            />
            <button type="button" class="as-btn as-btn--primary" wire:click="createProgram">Создать</button>
        </div>
    </div>

    @if ($folders->isNotEmpty() || $programs->isNotEmpty())
        <ul class="as-list" role="list">
            @foreach ($folders as $item)
                <li class="as-card-row" wire:key="folder-{{ $item->id }}">
                    <button type="button" class="as-card-row__main" wire:click="openFolder({{ $item->id }})">
                        <span class="as-avatar as-avatar--folder">
                            @include('filament.pages.asanas._icon', ['name' => 'folder'])
                        </span>
                        <span class="as-card-row__text">
                            <span class="as-card-row__title">{{ $item->name }}</span>
                            <span class="as-card-row__meta">
                                {{ $item->programs_count > 0 ? 'занятий: '.$item->programs_count : 'пустая папка' }}
                            </span>
                        </span>
                        @include('filament.pages.asanas._icon', ['name' => 'chevron', 'class' => 'as-card-row__chevron'])
                    </button>

                    <div class="as-card-row__actions">
                        <button
                            type="button"
                            class="as-icon-btn"
                            title="Переименовать"
                            @click="
                                const name = window.prompt('Название папки', @js($item->name));
                                if (name && name.trim()) $wire.renameFolder({{ $item->id }}, name);
                            "
                        >@include('filament.pages.asanas._icon', ['name' => 'pencil'])</button>
                        <button
                            type="button"
                            class="as-icon-btn as-icon-btn--danger"
                            title="Удалить"
                            @click="
                                if (window.confirm(@js('Удалить папку «'.$item->name.'»? Вложенные папки удалятся, занятия останутся вне папок.'))) {
                                    $wire.deleteFolder({{ $item->id }});
                                }
                            "
                        >@include('filament.pages.asanas._icon', ['name' => 'trash'])</button>
                    </div>
                </li>
            @endforeach

            @foreach ($programs as $item)
                <li class="as-card-row" wire:key="program-{{ $item->id }}">
                    <button type="button" class="as-card-row__main" wire:click="openProgram({{ $item->id }})">
                        <span class="as-avatar as-avatar--program">
                            @include('filament.pages.asanas._icon', ['name' => 'sparkles'])
                        </span>
                        <span class="as-card-row__text">
                            <span class="as-card-row__title">{{ $item->title }}</span>
                            <span class="as-card-row__meta">
                                {{ $item->items_count > 0 ? 'поз: '.$item->items_count : 'пока пусто' }}
                            </span>
                        </span>
                        @include('filament.pages.asanas._icon', ['name' => 'chevron', 'class' => 'as-card-row__chevron'])
                    </button>

                    <div class="as-card-row__actions">
                        <button
                            type="button"
                            class="as-icon-btn"
                            title="Сделать копию"
                            wire:click="duplicateProgram({{ $item->id }})"
                        >@include('filament.pages.asanas._icon', ['name' => 'copy'])</button>
                        <button
                            type="button"
                            class="as-icon-btn as-icon-btn--danger"
                            title="Удалить"
                            @click="
                                if (window.confirm(@js('Удалить занятие «'.$item->title.'»?'))) {
                                    $wire.deleteProgram({{ $item->id }});
                                }
                            "
                        >@include('filament.pages.asanas._icon', ['name' => 'trash'])</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <div class="as-empty">
            <span class="as-empty__icon">
                @include('filament.pages.asanas._icon', ['name' => 'folder'])
            </span>
            <p class="as-empty__title">Здесь пока пусто</p>
            <p class="as-empty__text">
                Создайте папку — например, «Растяжка» — и складывайте в неё занятия.
                Папки можно вкладывать друг в друга.
            </p>
        </div>
    @endif
</div>
