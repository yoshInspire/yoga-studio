@extends('layouts.site')

@section('title', 'Расписание занятий — Студия йоги Ирины Коленцевой')

@php
  // Демо-данные недели. В рабочей версии расписание формирует администратор,
  // запись открывается на неделю вперёд, лимит мест задаётся для каждого занятия.
  $week = [
    ['key' => 'mon', 'name' => 'Пн', 'date' => '3 июня', 'slots' => [
      ['time' => '08:00', 'title' => 'Хатха-йога', 'trainer' => 'Ирина Коленцева', 'type' => 'group', 'taken' => 3, 'total' => 6, 'status' => 'open'],
      ['time' => '10:30', 'title' => 'Здоровая спина', 'trainer' => 'Ирина Коленцева', 'type' => 'group', 'taken' => 5, 'total' => 6, 'status' => 'open'],
      ['time' => '19:00', 'title' => 'Инь-йога', 'trainer' => 'Ирина Коленцева', 'type' => 'group', 'taken' => 6, 'total' => 6, 'status' => 'full'],
    ]],
    ['key' => 'tue', 'name' => 'Вт', 'date' => '4 июня', 'slots' => [
      ['time' => '09:00', 'title' => 'Мобилити-йога', 'trainer' => 'Александр', 'type' => 'group', 'taken' => 2, 'total' => 6, 'status' => 'open'],
      ['time' => '12:00', 'title' => 'Индивидуальное занятие', 'trainer' => 'Ирина Коленцева', 'type' => 'indiv', 'taken' => 0, 'total' => 1, 'status' => 'open'],
      ['time' => '18:30', 'title' => 'Функциональный тренинг', 'trainer' => 'Александр', 'type' => 'group', 'taken' => 4, 'total' => 6, 'status' => 'open'],
    ]],
    ['key' => 'wed', 'name' => 'Ср', 'date' => '5 июня', 'slots' => [
      ['time' => '08:00', 'title' => 'Хатха-йога', 'trainer' => 'Ирина Коленцева', 'type' => 'group', 'taken' => 1, 'total' => 6, 'status' => 'open'],
      ['time' => '11:00', 'title' => 'Йога для беременных', 'trainer' => 'Ирина Коленцева', 'type' => 'group', 'taken' => 3, 'total' => 6, 'status' => 'open'],
      ['time' => '20:00', 'title' => 'Страус-йога на хедстендере', 'trainer' => 'Александр', 'type' => 'group', 'taken' => 2, 'total' => 5, 'status' => 'open'],
    ]],
    ['key' => 'thu', 'name' => 'Чт', 'date' => '6 июня', 'slots' => [
      ['time' => '10:00', 'title' => 'Женское здоровье', 'trainer' => 'Ирина Коленцева', 'type' => 'group', 'taken' => 6, 'total' => 6, 'status' => 'full'],
      ['time' => '19:00', 'title' => 'Аштанга-йога', 'trainer' => 'Александр', 'type' => 'group', 'taken' => 3, 'total' => 6, 'status' => 'open'],
    ]],
    ['key' => 'fri', 'name' => 'Пт', 'date' => '7 июня', 'slots' => [
      ['time' => '09:00', 'title' => 'Пилатес', 'trainer' => 'Александр', 'type' => 'group', 'taken' => 2, 'total' => 6, 'status' => 'open'],
      ['time' => '18:00', 'title' => 'Инь-йога', 'trainer' => 'Ирина Коленцева', 'type' => 'group', 'taken' => 1, 'total' => 6, 'status' => 'cancelled', 'reason' => 'Недостаточное количество участников в группе'],
    ]],
    ['key' => 'sat', 'name' => 'Сб', 'date' => '8 июня', 'slots' => [
      ['time' => '11:00', 'title' => 'Йога-нидра (мероприятие)', 'trainer' => 'Ирина Коленцева', 'type' => 'event', 'taken' => 8, 'total' => 12, 'status' => 'open'],
      ['time' => '16:00', 'title' => 'Медитация', 'trainer' => 'Ирина Коленцева', 'type' => 'group', 'taken' => 4, 'total' => 8, 'status' => 'open'],
    ]],
    ['key' => 'sun', 'name' => 'Вс', 'date' => '9 июня', 'slots' => [
      ['time' => '11:00', 'title' => 'Хатха-йога', 'trainer' => 'Ирина Коленцева', 'type' => 'group', 'taken' => 2, 'total' => 6, 'status' => 'open'],
    ]],
  ];

  $typeLabels = ['group' => 'Групповое', 'indiv' => 'Индивидуальное', 'event' => 'Мероприятие'];
@endphp

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
          <button type="button" class="sched__weeknav" aria-label="Предыдущая неделя">‹</button>
          <span class="sched__weeklabel">3&nbsp;–&nbsp;9&nbsp;июня</span>
          <button type="button" class="sched__weeknav" aria-label="Следующая неделя">›</button>
        </div>
      </div>

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
                  <h3 class="slot__title">{{ $slot['title'] }}</h3>
                  <p class="slot__meta">
                    <span class="badge badge--{{ $slot['type'] }}">{{ $typeLabels[$slot['type']] }}</span>
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
                  @if($slot['status'] === 'open')
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
            <div class="faq__a"><p>Отменить запись можно не позднее чем за&nbsp;4&nbsp;часа до начала занятия — тогда занятие вернётся на абонемент. При более поздней отмене занятие списывается.</p></div>
          </div>
          <div class="faq__item">
            <button type="button" class="faq__q">Сколько человек в группе<span class="faq__icon">+</span></button>
            <div class="faq__a"><p>Группы маленькие — до&nbsp;6&nbsp;человек, на отдельных занятиях лимит может отличаться. Количество свободных мест видно прямо в расписании.</p></div>
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
