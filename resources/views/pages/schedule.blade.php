@extends('layouts.site')

@section('title', 'Расписание занятий — Студия йоги Ирины Коленцевой')

@section('content')
  <section class="section sched">
    <div class="container">
      <div class="sched__head reveal">
        <div>
          <p class="eyebrow">Расписание</p>
          <h1 class="section__title">Расписание занятий</h1>
          <p class="sched__note">
            Запись открывается на&nbsp;неделю вперёд. Расписание доступно всем —
            чтобы записаться, войдите в <a href="{{ route('login') }}">личный кабинет</a>.
          </p>
        </div>
        <div class="sched__week" aria-label="Выбор недели">
          <a href="{{ route('schedule', ['week' => $prevWeek]) }}" class="sched__weeknav" aria-label="Предыдущая неделя">‹</a>
          <span class="sched__weeklabel">{{ $weekLabel }}</span>
          <a href="{{ route('schedule', ['week' => $nextWeek]) }}" class="sched__weeknav" aria-label="Следующая неделя">›</a>
        </div>
      </div>

      @if (session('status'))
        <div class="auth__alert auth__alert--ok reveal" style="margin-bottom: 20px">{{ session('status') }}</div>
      @endif
      @if ($errors->has('booking'))
        <div class="auth__alert auth__alert--error reveal" style="margin-bottom: 20px">{{ $errors->first('booking') }}</div>
      @endif

      <div class="sched__days reveal" role="tablist" id="schedDays">
        @foreach($week as $i => $day)
          <button type="button" class="sched__day {{ $i === 0 ? 'is-active' : '' }}"
                  data-day="{{ $day['key'] }}" role="tab" aria-selected="{{ $i === 0 ? 'true' : 'false' }}">
            <span class="sched__day-name">{{ $day['name'] }}</span>
            <span class="sched__day-date">{{ $day['date'] }}</span>
          </button>
        @endforeach
      </div>

      <div class="sched__board reveal">
        @foreach($week as $i => $day)
          <div class="sched__panel {{ $i === 0 ? '' : 'is-hidden' }}" data-panel="{{ $day['key'] }}">
            @forelse($day['slots'] as $slot)
              @php
                $free = $slot['total'] - $slot['taken'];
                $cls = $slot['status'] === 'full' ? 'slot--full' : ($slot['status'] === 'cancelled' ? 'slot--cancelled' : '');
              @endphp
              <div class="slot {{ $cls }}">
                <div class="slot__time">{{ $slot['time'] }}</div>
                <div class="slot__main">
                  <h3 class="slot__title">{{ $slot['direction'] ?: $slot['topic'] ?: $slot['title'] }}</h3>
                  @if(!empty($slot['direction']) && !empty($slot['topic']))
                    <p class="slot__topic">{{ $slot['topic'] }}</p>
                  @endif
                  <p class="slot__meta">
                    <span class="badge badge--{{ $slot['type'] }}">{{ $typeLabels[$slot['type']] ?? $slot['type'] }}</span>
                    <span class="slot__trainer">{{ $slot['trainer'] }}</span>
                  </p>
                </div>
                <div class="slot__seats">
                  @if($slot['status'] === 'cancelled')
                    <span class="slot__seats-num slot__seats-num--off">Отменено</span>
                    <span class="slot__seats-label">{{ $slot['reason'] }}</span>
                  @elseif($slot['status'] === 'full')
                    <span class="slot__seats-num slot__seats-num--off">Мест нет</span>
                    <span class="slot__seats-label">{{ $slot['total'] }} из {{ $slot['total'] }} занято</span>
                  @else
                    <span class="slot__seats-num">{{ $free }}</span>
                    <span class="slot__seats-label">свободно из {{ $slot['total'] }}</span>
                  @endif
                </div>
                <div class="slot__action">
                  @if($slot['user_booked'] ?? false)
                    <span class="btn btn--ghost" style="pointer-events:none">Вы записаны</span>
                  @elseif($slot['status'] === 'open' && ($slot['bookable'] ?? false) && auth()->check() && auth()->user()->isClient())
                    <form action="{{ route('bookings.store') }}" method="post">
                      @csrf
                      <input type="hidden" name="class_session_id" value="{{ $slot['id'] }}" />
                      <button type="submit" class="btn btn--solid">Записаться</button>
                    </form>
                  @elseif($slot['status'] === 'open' && ($slot['bookable'] ?? false))
                    <a href="{{ route('login') }}" class="btn btn--solid">Записаться</a>
                  @elseif($slot['status'] === 'full')
                    <button type="button" class="btn btn--ghost" disabled>Мест нет</button>
                  @else
                    <button type="button" class="btn btn--ghost" disabled>Отменено</button>
                  @endif
                </div>
              </div>
            @empty
              <p class="sched__empty">В этот день занятий нет.</p>
            @endforelse
          </div>
        @endforeach
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
            <div class="faq__a"><p>Отменить запись можно заранее, тогда занятие вернётся на абонемент. Для занятий <b>до&nbsp;12:00</b> — не позднее чем за&nbsp;14&nbsp;часов до начала, для занятий <b>с&nbsp;12:00</b> — не позднее чем за&nbsp;4&nbsp;часа. При более поздней отмене занятие списывается.</p></div>
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
            <div class="faq__a"><p>Доступны групповые и индивидуальные абонементы, а также разовые занятия и мероприятия студии (например, йога-нидра) — они оплачиваются отдельно и не входят в обычный абонемент. Первое занятие — пробное.</p></div>
          </div>
        </div>
      </div>
    </div>
  </section>
@endsection
