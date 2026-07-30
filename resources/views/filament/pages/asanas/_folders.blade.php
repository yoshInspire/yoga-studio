@php
    /** @var \Illuminate\Support\Collection $folders */
    /** @var \Illuminate\Support\Collection $programs */
@endphp

<div wire:key="folders-{{ $folderId ?? 'root' }}">
    {{-- Путь по папкам --}}
    <nav class="as-crumbs" aria-label="Путь по папкам">
        <button type="button" class="as-crumb" wire:click="openFolder(null)">Все папки</button>
        @foreach ($breadcrumbs as $crumb)
            <span class="as-crumb__sep" aria-hidden="true">›</span>
            @if ($loop->last)
                <span class="as-crumb as-crumb--current">{{ $crumb->name }}</span>
            @else
                <button type="button" class="as-crumb" wire:click="openFolder({{ $crumb->id }})">{{ $crumb->name }}</button>
            @endif
        @endforeach
    </nav>

    {{-- Создание --}}
    <div class="as-create">
        <div class="as-create__row">
            <input
                type="text"
                class="as-input"
                placeholder="Новая папка — например, Растяжка"
                wire:model="newFolderName"
                wire:keydown.enter.prevent="createFolder"
                aria-label="Название новой папки"
            />
            <button type="button" class="as-btn as-btn--ghost" wire:click="createFolder">
                Папка
            </button>
        </div>

        <div class="as-create__row">
            <input
                type="text"
                class="as-input"
                placeholder="Новое занятие — например, Шпагаты"
                wire:model="newProgramTitle"
                wire:keydown.enter.prevent="createProgram"
                aria-label="Название нового занятия"
            />
            <button type="button" class="as-btn as-btn--primary" wire:click="createProgram">
                Занятие
            </button>
        </div>
    </div>

    {{-- Папки --}}
    @if ($folders->isNotEmpty())
        <ul class="as-list" role="list">
            @foreach ($folders as $item)
                <li class="as-row" wire:key="folder-{{ $item->id }}">
                    <button type="button" class="as-row__main" wire:click="openFolder({{ $item->id }})">
                        <span class="as-row__icon" aria-hidden="true">📁</span>
                        <span class="as-row__text">
                            <span class="as-row__title">{{ $item->name }}</span>
                            <span class="as-row__meta">
                                @if ($item->programs_count > 0)
                                    занятий: {{ $item->programs_count }}
                                @else
                                    пусто
                                @endif
                            </span>
                        </span>
                        <span class="as-row__chevron" aria-hidden="true">›</span>
                    </button>

                    <div class="as-row__actions">
                        <button
                            type="button"
                            class="as-icon-btn"
                            title="Переименовать папку"
                            @click="
                                const name = window.prompt('Название папки', @js($item->name));
                                if (name && name.trim()) $wire.renameFolder({{ $item->id }}, name);
                            "
                        >✎</button>
                        <button
                            type="button"
                            class="as-icon-btn as-icon-btn--danger"
                            title="Удалить папку"
                            @click="
                                if (window.confirm(@js('Удалить папку «'.$item->name.'»? Занятия внутри останутся, но окажутся вне папок.'))) {
                                    $wire.deleteFolder({{ $item->id }});
                                }
                            "
                        >🗑</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Занятия --}}
    @if ($programs->isNotEmpty())
        <ul class="as-list" role="list">
            @foreach ($programs as $item)
                <li class="as-row" wire:key="program-{{ $item->id }}">
                    <button type="button" class="as-row__main" wire:click="openProgram({{ $item->id }})">
                        <span class="as-row__icon" aria-hidden="true">🧘</span>
                        <span class="as-row__text">
                            <span class="as-row__title">{{ $item->title }}</span>
                            <span class="as-row__meta">
                                @if ($item->items_count > 0)
                                    поз: {{ $item->items_count }}
                                @else
                                    пока пусто
                                @endif
                            </span>
                        </span>
                        <span class="as-row__chevron" aria-hidden="true">›</span>
                    </button>

                    <div class="as-row__actions">
                        <button
                            type="button"
                            class="as-icon-btn"
                            title="Сделать копию занятия"
                            wire:click="duplicateProgram({{ $item->id }})"
                        >⧉</button>
                        <button
                            type="button"
                            class="as-icon-btn as-icon-btn--danger"
                            title="Удалить занятие"
                            @click="
                                if (window.confirm(@js('Удалить занятие «'.$item->title.'»?'))) {
                                    $wire.deleteProgram({{ $item->id }});
                                }
                            "
                        >🗑</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($folders->isEmpty() && $programs->isEmpty())
        <p class="as-empty">
            Здесь пока пусто. Создайте папку — например, «Растяжка» — и внутри неё занятия.
        </p>
    @endif
</div>
