@php
    /** @var \Illuminate\Support\Collection $asanas */
    /** @var \Illuminate\Support\Collection $categories */
@endphp

<div wire:key="library-view">
    <div class="as-libhead">
        <button type="button" class="as-back" wire:click="backToProgram">
            @include('filament.pages.asanas._icon', ['name' => 'chevron', 'class' => 'as-back__icon'])
            К занятию
        </button>

        <div class="as-search">
            @include('filament.pages.asanas._icon', ['name' => 'search', 'class' => 'as-search__icon'])
            <input
                type="search"
                class="as-input as-input--search"
                placeholder="Поиск позы — например, шванасана"
                wire:model.live.debounce.300ms="search"
                aria-label="Поиск позы"
            />
        </div>

        <div class="as-chips" role="group" aria-label="Разделы">
            <button
                type="button"
                class="as-chip @if ($categoryFilter === null) as-chip--on @endif"
                wire:click="$set('categoryFilter', null)"
            >Все</button>
            @foreach ($categories as $category)
                <button
                    type="button"
                    class="as-chip @if ($categoryFilter === $category) as-chip--on @endif"
                    wire:click="$set('categoryFilter', @js($category))"
                >{{ $category }}</button>
            @endforeach
            <button
                type="button"
                class="as-chip as-chip--manage @if ($managingCategories) as-chip--on @endif"
                title="Настроить разделы"
                wire:click="toggleCategoryManager"
            >@include('filament.pages.asanas._icon', ['name' => 'pencil']) Разделы</button>
        </div>

        {{-- Управление разделами --}}
        @if ($managingCategories)
            <div class="as-cats">
                <div class="as-cats__add">
                    <input
                        type="text"
                        class="as-input as-input--sm"
                        placeholder="Новый раздел — например, Скрутки"
                        wire:model="newCategoryName"
                        wire:keydown.enter.prevent="createCategory"
                        aria-label="Название нового раздела"
                    />
                    <button type="button" class="as-btn as-btn--primary" wire:click="createCategory">
                        @include('filament.pages.asanas._icon', ['name' => 'plus'])
                        Создать
                    </button>
                </div>

                @forelse ($categoryRows as $row)
                    <div class="as-cats__row" wire:key="cat-{{ $row->id }}">
                        <span class="as-cats__name">{{ $row->name }}</span>
                        <span class="as-cats__count">{{ $row->asanaCount() }}</span>
                        <button
                            type="button"
                            class="as-icon-btn"
                            title="Переименовать раздел"
                            @click="
                                const name = window.prompt('Название раздела', @js($row->name));
                                if (name && name.trim()) $wire.renameCategory({{ $row->id }}, name);
                            "
                        >@include('filament.pages.asanas._icon', ['name' => 'pencil'])</button>
                        <button
                            type="button"
                            class="as-icon-btn as-icon-btn--danger"
                            title="Удалить раздел"
                            @click="
                                if (window.confirm(@js('Удалить раздел «'.$row->name.'»? Позы останутся, но окажутся без раздела.'))) {
                                    $wire.deleteCategory({{ $row->id }});
                                }
                            "
                        >@include('filament.pages.asanas._icon', ['name' => 'trash'])</button>
                    </div>
                @empty
                    <p class="as-hint">Разделов пока нет — создайте первый.</p>
                @endforelse

                <p class="as-hint">
                    Позы при удалении раздела не пропадают — они просто останутся без него.
                </p>
            </div>
        @endif
    </div>

    @if ($asanas->isEmpty())
        <div class="as-empty">
            <span class="as-empty__icon">
                @include('filament.pages.asanas._icon', ['name' => 'search'])
            </span>
            <p class="as-empty__title">Ничего не нашлось</p>
            <p class="as-empty__text">Измените запрос или зарисуйте свою позу.</p>
            <button type="button" class="as-btn as-btn--primary" wire:click="startNewDrawing">
                @include('filament.pages.asanas._icon', ['name' => 'pencil'])
                Зарисовать
            </button>
        </div>
    @else
        <div class="as-grid">
            @foreach ($asanas as $asana)
                <div class="as-card" wire:key="asana-{{ $asana->id }}">
                    <button
                        type="button"
                        class="as-card__btn"
                        wire:click="addAsana({{ $asana->id }})"
                        title="{{ $asana->name }}"
                    >
                        <span class="as-card__thumb">
                            <img src="{{ $asana->imageUrl() }}" alt="{{ $asana->name }}" loading="lazy" />
                            <span class="as-card__add">
                                @include('filament.pages.asanas._icon', ['name' => 'plus'])
                            </span>
                        </span>
                        <span class="as-card__name">{{ $asana->name }}</span>
                    </button>

                    @if ($asana->is_custom)
                        <button
                            type="button"
                            class="as-card__del"
                            title="Удалить свою зарисовку"
                            @click="
                                if (window.confirm(@js('Удалить зарисовку «'.$asana->name.'»?'))) {
                                    $wire.deleteCustomAsana({{ $asana->id }});
                                }
                            "
                        >@include('filament.pages.asanas._icon', ['name' => 'close'])</button>

                        {{-- Раздел своей зарисовки: можно переложить к готовым позам --}}
                        <select
                            class="as-card__cat"
                            aria-label="Раздел для «{{ $asana->name }}»"
                            title="В каком разделе показывать"
                            wire:change="setAsanaCategory({{ $asana->id }}, $event.target.value)"
                        >
                            <option value="" @selected($asana->category === null)>Мои зарисовки</option>
                            @foreach ($libraryCategories ?? [] as $category)
                                <option value="{{ $category }}" @selected($asana->category === $category)>
                                    {{ $category }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($asanas->count() >= 300)
            <p class="as-hint">Показаны первые 300 поз — уточните поиск.</p>
        @endif
    @endif

    <div class="as-bar">
        <button type="button" class="as-btn as-btn--ghost" wire:click="startNewDrawing">
            @include('filament.pages.asanas._icon', ['name' => 'pencil'])
            Нет нужной позы — зарисовать
        </button>
    </div>
</div>
