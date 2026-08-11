@extends('layouts.site')

@section('title', 'Расписание занятий — Студия йоги Ирины Коленцевой')
@section('meta_description', 'Расписание занятий йоги в студии Ирины Коленцевой (Коньково, Москва): групповые и индивидуальные классы. Бронирование через личный кабинет.')
@section('canonical', route('schedule'))

@section('content')
  @php
    $navQuery = fn (int $targetOffset) => array_filter([
        'offset' => $targetOffset > 0 ? $targetOffset : null,
        'reschedule' => ($rescheduleFrom ?? null)?->id,
        'directions' => $selectedDirections ? implode(',', $selectedDirections) : null,
    ], fn ($value) => $value !== null);
  @endphp

  <section class="section sched">
    <div class="container">
      <div class="sched__head reveal">
        <div>
          <p class="eyebrow">Расписание</p>
          <h1 class="section__title">Расписание занятий</h1>
          <p class="sched__note">
            Открытие бронирования на&nbsp;неделю вперёд — по воскресеньям до&nbsp;14:00. Расписание доступно всем —
            чтобы забронировать место, войдите в <a href="{{ route('login') }}">личный кабинет</a>.
          </p>
        </div>
        <div class="sched__range" aria-label="Период расписания">
          <span class="sched__range-label" id="schedRangeLabel">{{ $rangeLabel }}</span>
        </div>
      </div>

      @if (session('status'))
        <div class="auth__alert auth__alert--ok reveal" style="margin-bottom: 20px">{{ session('status') }}</div>
      @endif
      @if ($errors->has('booking'))
        <div class="auth__alert auth__alert--error reveal" style="margin-bottom: 20px">{{ $errors->first('booking') }}</div>
      @endif

      @if ($rescheduleFrom ?? null)
        <div class="auth__alert auth__alert--ok reveal" style="margin-bottom: 20px">
          Перенос бронирования с «{{ $rescheduleFrom->classSession->title }}»
          {{ $rescheduleFrom->classSession->formattedDateTime() }}.
          Выберите новое занятие и нажмите «Перенести сюда».
          <a href="{{ route('schedule', $navQuery($offset)) }}" class="auth__minor" style="margin-left: 8px">Отменить перенос</a>
        </div>
      @endif

      @if (count($directionOptions) > 1)
        @php
          // Ссылки на случай, когда JS не отработал: каждый чип — обычная ссылка
          // с уже пересчитанным набором направлений.
          $filterUrl = function (array $slugs) use ($offset, $rescheduleFrom) {
              return route('schedule', array_filter([
                  'offset' => $offset > 0 ? $offset : null,
                  'reschedule' => ($rescheduleFrom ?? null)?->id,
                  'directions' => $slugs ? implode(',', $slugs) : null,
              ], fn ($value) => $value !== null));
          };
          $toggled = function (string $slug) use ($directionOptions, $selectedDirections) {
              $next = in_array($slug, $selectedDirections, true)
                  ? array_diff($selectedDirections, [$slug])
                  : array_merge($selectedDirections, [$slug]);

              return array_values(array_filter(
                  array_column($directionOptions, 'slug'),
                  fn (string $item) => in_array($item, $next, true),
              ));
          };
        @endphp

        <div class="sched-filter reveal" id="schedFilter">
          <p class="sched-filter__label" id="schedFilterLabel">Направления</p>
          <div class="sched-filter__chips" role="group" aria-labelledby="schedFilterLabel">
            <a class="sched-filter__chip {{ $selectedDirections ? '' : 'is-active' }}"
               href="{{ $filterUrl([]) }}"
               data-dir-filter=""
               @if(! $selectedDirections) aria-current="true" @endif>Все</a>
            @foreach ($directionOptions as $option)
              @php $isActive = in_array($option['slug'], $selectedDirections, true); @endphp
              <a class="sched-filter__chip {{ $isActive ? 'is-active' : '' }}"
                 href="{{ $filterUrl($toggled($option['slug'])) }}"
                 data-dir-filter="{{ $option['slug'] }}"
                 @if($isActive) aria-current="true" @endif>{{ $option['title'] }}</a>
            @endforeach
          </div>
        </div>
      @endif

      <div class="gridsched reveal" id="gridSchedule">
        <div class="gridsched__stage" id="gridschedStage">
          @include('partials.schedule-grid')
        </div>
      </div>

      @include('partials.studio-rules')
    </div>
  </section>

  <div class="sched-modal" id="schedModal" aria-hidden="true">
    <div class="sched-modal__backdrop" data-sched-close></div>
    <div class="sched-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="schedModalTitle">
      <button type="button" class="sched-modal__close" data-sched-close aria-label="Закрыть">&times;</button>

      <div class="sched-modal__head">
        <span class="badge" id="schedModalType"></span>
        <p class="sched-modal__datetime" id="schedModalDatetime"></p>
        <h2 class="sched-modal__title" id="schedModalTitle"></h2>
        <p class="sched-modal__topic" id="schedModalTopic" hidden></p>
      </div>

      <div class="sched-modal__meta">
        <div class="sched-modal__row">
          <span class="sched-modal__label">Преподаватель</span>
          <span class="sched-modal__value" id="schedModalTrainer"></span>
        </div>
        <div class="sched-modal__row">
          <span class="sched-modal__label">Длительность</span>
          <span class="sched-modal__value" id="schedModalDuration"></span>
        </div>
        <div class="sched-modal__row">
          <span class="sched-modal__label">Места</span>
          <span class="sched-modal__value" id="schedModalSeats"></span>
        </div>
      </div>

      <p class="sched-modal__desc" id="schedModalDesc" hidden></p>

      <div class="sched-modal__action" id="schedModalAction"></div>
    </div>
  </div>

  @php
    $schedConfig = [
        'bookUrl' => route('bookings.store'),
        'loginUrl' => route('login'),
        'scheduleUrl' => route('schedule'),
        'csrf' => csrf_token(),
        'offset' => $offset,
        'directions' => $selectedDirections,
        'typeLabels' => $typeLabels,
        'rescheduleFrom' => ($rescheduleFrom ?? null)?->id,
        'rescheduleUrl' => ($rescheduleFrom ?? null) ? route('bookings.reschedule', $rescheduleFrom) : null,
        'isClient' => auth()->check() && auth()->user()->isClient(),
    ];
  @endphp
  <script>
    window.__schedConfig = @json($schedConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
  </script>
@endsection
