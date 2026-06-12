@extends('layouts.site')

@section('title', 'Расписание занятий — Студия йоги Ирины Коленцевой')
@section('meta_description', 'Расписание занятий йоги в студии Ирины Коленцевой (Коньково, Москва): групповые и индивидуальные классы. Запись через личный кабинет.')
@section('canonical', route('schedule'))

@section('content')
  @php
    $navQuery = fn (int $targetOffset) => array_filter([
        'offset' => $targetOffset > 0 ? $targetOffset : null,
        'reschedule' => ($rescheduleFrom ?? null)?->id,
    ], fn ($value) => $value !== null);
  @endphp

  <section class="section sched">
    <div class="container">
      <div class="sched__head reveal">
        <div>
          <p class="eyebrow">Расписание</p>
          <h1 class="section__title">Расписание занятий</h1>
          <p class="sched__note">
            Открытие записи на&nbsp;неделю вперёд — по воскресеньям до&nbsp;14:00. Расписание доступно всем —
            чтобы записаться, войдите в <a href="{{ route('login') }}">личный кабинет</a>.
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
          Перенос записи с «{{ $rescheduleFrom->classSession->title }}»
          {{ $rescheduleFrom->classSession->formattedDateTime() }}.
          Выберите новое занятие и нажмите «Перенести сюда».
          <a href="{{ route('schedule', $navQuery($offset)) }}" class="auth__minor" style="margin-left: 8px">Отменить перенос</a>
        </div>
      @endif

      <div class="gridsched reveal" id="gridSchedule">
        <div class="gridsched__stage" id="gridschedStage">
          @include('partials.schedule-grid')
        </div>
      </div>

      <div class="faq reveal">
        <h2 class="faq__title">Правила записи</h2>
        <div class="faq__list" id="faqList">
          <div class="faq__item">
            <button type="button" class="faq__q">Как записаться на занятие<span class="faq__icon">+</span></button>
            <div class="faq__a"><p>Запись доступна в личном кабинете после входа. Выберите день и занятие на ближайшую неделю — система спишет занятие с подходящего абонемента и покажет остаток свободных мест.</p></div>
          </div>
          <div class="faq__item">
            <button type="button" class="faq__q">Отмена записи<span class="faq__icon">+</span></button>
            <div class="faq__a"><p>Отменить запись можно заранее, тогда занятие вернётся на абонемент. Для занятий <b>до&nbsp;12:00</b> — не позднее чем за&nbsp;14&nbsp;часов до начала, для занятий <b>с&nbsp;12:00</b> — не позднее чем за&nbsp;4&nbsp;часа. При более поздней отмене занятие списывается. В те же сроки можно <b>перенести</b> запись на другое время: в «Мои записи» нажмите «Перенести» и выберите новое занятие в расписании.</p></div>
          </div>
          <div class="faq__item">
            <button type="button" class="faq__q">Сколько человек в группе<span class="faq__icon">+</span></button>
            <div class="faq__a"><p>Группы маленькие — до&nbsp;6&nbsp;человек, на отдельных занятиях лимит может отличаться. Количество свободных мест видно прямо в расписании.</p></div>
          </div>
          <div class="faq__item">
            <button type="button" class="faq__q">Что если группа не набралась<span class="faq__icon">+</span></button>
            <div class="faq__a"><p>Если на групповое занятие записалось меньше&nbsp;2&nbsp;человек, оно может быть отменено заранее. Запись аннулируется, занятие возвращается на абонемент, а вам приходит уведомление на почту и&nbsp;в&nbsp;Telegram (если они привязаны).</p></div>
          </div>
          <div class="faq__item">
            <button type="button" class="faq__q">Абонементы и разовые занятия<span class="faq__icon">+</span></button>
            <div class="faq__a"><p>Доступны групповые и индивидуальные абонементы, а также разовые занятия и мероприятия студии (например, йога-нидра) — они оплачиваются отдельно и не входят в обычный абонемент.</p><p>Первое занятие — пробное.</p></div>
          </div>
        </div>
      </div>
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
