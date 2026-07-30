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

        <div class="as-chips" role="group" aria-label="Категории">
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
        </div>
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
