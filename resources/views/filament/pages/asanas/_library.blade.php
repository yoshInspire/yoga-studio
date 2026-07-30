@php
    /** @var \Illuminate\Support\Collection $asanas */
    /** @var \Illuminate\Support\Collection $categories */
@endphp

<div wire:key="library-view">
    <div class="as-head">
        <button type="button" class="as-back" wire:click="backToProgram">‹ К занятию</button>
        <h2 class="as-head__title">База поз</h2>
    </div>

    {{-- Поиск --}}
    <input
        type="search"
        class="as-input"
        placeholder="Поиск по названию — например, шванасана"
        wire:model.live.debounce.300ms="search"
        aria-label="Поиск позы"
    />

    {{-- Категории --}}
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

    <div class="as-actions">
        <button type="button" class="as-btn as-btn--ghost" wire:click="startNewDrawing">
            ✎ Нет нужной позы — зарисовать
        </button>
    </div>

    {{-- Сетка поз --}}
    @if ($asanas->isEmpty())
        <p class="as-empty">Ничего не нашлось. Измените запрос или зарисуйте свою позу.</p>
    @else
        <div class="as-grid">
            @foreach ($asanas as $asana)
                <div class="as-card" wire:key="asana-{{ $asana->id }}">
                    <button
                        type="button"
                        class="as-card__btn"
                        wire:click="addAsana({{ $asana->id }})"
                        title="Добавить в занятие"
                    >
                        <img src="{{ $asana->imageUrl() }}" alt="{{ $asana->name }}" loading="lazy" />
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
                        >×</button>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($asanas->count() >= 300)
            <p class="as-empty">Показаны первые 300 поз — уточните поиск.</p>
        @endif
    @endif
</div>
