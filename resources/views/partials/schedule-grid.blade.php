<div class="gridsched__header">
  @if ($canGoPrev)
    <button type="button"
            class="gridsched__arrow gridsched__arrow--prev"
            data-sched-nav="prev"
            data-offset="{{ $prevOffset }}"
            aria-label="На неделю назад">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
  @else
    <span class="gridsched__arrow gridsched__arrow--prev is-disabled" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </span>
  @endif

  <div class="gridsched__head-main">
    <div class="gridsched__days" aria-hidden="true">
      @foreach($days as $day)
        <div class="gridsched__dayhead {{ $day['is_today'] ? 'gridsched__dayhead--today' : '' }}">
          @if($day['is_today'])
            <span class="gridsched__daytoday">Сегодня</span>
          @else
            <span class="gridsched__daylabel">{{ $day['label'] }}</span>
          @endif
        </div>
      @endforeach
    </div>

    <div class="gridsched__mobnav" role="tablist" id="gridschedMobnav" aria-label="Выбор дня">
      @foreach($days as $i => $day)
        <button type="button"
                class="gridsched__mobtab {{ $i === 0 ? 'is-active' : '' }}"
                data-day-tab="{{ $i }}"
                role="tab"
                aria-selected="{{ $i === 0 ? 'true' : 'false' }}">
          @if($day['is_today'])
            <span class="gridsched__mobtab-main">Сегодня</span>
          @else
            <span class="gridsched__mobtab-main">{{ $day['name'] }}</span>
            <span class="gridsched__mobtab-sub">{{ $day['date'] }}</span>
          @endif
        </button>
      @endforeach
    </div>
  </div>

  <button type="button"
          class="gridsched__arrow gridsched__arrow--next"
          data-sched-nav="next"
          data-offset="{{ $nextOffset }}"
          aria-label="На неделю вперёд">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </button>
</div>

<div class="gridsched__body">
  @forelse($rows as $row)
    <div class="gridsched__row">
      <div class="gridsched__time" aria-hidden="true">{{ $row['time'] }}</div>

      <div class="gridsched__cells">
        @foreach($row['cells'] as $slot)
          <div class="gridsched__cell">
            @if($slot)
              @include('partials.schedule-card', ['slot' => $slot])
            @endif
          </div>
        @endforeach
      </div>

      <div class="gridsched__time gridsched__time--right" aria-hidden="true">{{ $row['time'] }}</div>
    </div>
  @empty
    <p class="gridsched__empty">
      @if (! empty($selectedDirections))
        По выбранным направлениям в этом периоде занятий нет.
      @else
        В выбранном периоде занятий пока нет.
      @endif
    </p>
  @endforelse
</div>

<div class="gridsched__mobile" id="gridschedMobile">
  @foreach($days as $i => $day)
    <div class="gridsched__mobile-panel {{ $i === 0 ? '' : 'is-hidden' }}"
         data-day-panel="{{ $i }}"
         role="tabpanel">
      @forelse($day['slots'] as $slot)
        @include('partials.schedule-card', ['slot' => $slot])
      @empty
        <p class="gridsched__mobile-empty">В этот день занятий нет.</p>
      @endforelse
    </div>
  @endforeach
</div>
