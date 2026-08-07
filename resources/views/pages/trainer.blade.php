@extends('layouts.site')

@section('title', 'Кабинет тренера — Студия йоги Ирины Коленцевой')
@section('robots', 'noindex, nofollow')

@section('content')
  <section class="section sched trainer">
    <div class="container">
      <div class="sched__head reveal">
        <div>
          <p class="eyebrow">Кабинет тренера</p>
          <h1 class="section__title">Мои занятия</h1>
          <p class="sched__note">
            Здесь видны только занятия, на которые вас назначили. Список гостей — имя и фамилия, без контактов.
            Редактирование недоступно.
          </p>
        </div>
        <div class="sched__week" aria-label="Выбор недели">
          <a href="{{ route('trainer', ['week' => $prevWeek]) }}" class="sched__weeknav" aria-label="Предыдущая неделя">‹</a>
          <span class="sched__weeklabel">{{ $weekLabel }}</span>
          <a href="{{ route('trainer', ['week' => $nextWeek]) }}" class="sched__weeknav" aria-label="Следующая неделя">›</a>
        </div>
      </div>

      <div class="trainer__user reveal">
        @include('partials.avatar-editor', ['avatarUser' => $trainer])
        <div>
          <strong>{{ $trainer->shortName() }}</strong>
          <span class="trainer__role">Тренер студии</span>
          <button type="button" class="lk__user-photo" data-avatar-open>
            {{ $trainer->hasAvatar() ? 'Сменить фото' : 'Добавить фото' }}
          </button>
          @if ($errors->has('photo'))
            <p class="avatar__error">{{ $errors->first('photo') }}</p>
          @endif
        </div>
        <form action="{{ route('logout') }}" method="post" class="trainer__logout">
          @csrf
          <button type="submit" class="btn btn--ghost">Выйти</button>
        </form>
      </div>

      @php
        $activeDayKey = collect($week)->firstWhere('is_today', true)['key'] ?? $week[0]['key'];
      @endphp

      <div class="sched__days reveal" role="tablist" id="schedDays">
        @foreach($week as $day)
          @php $isActive = $day['key'] === $activeDayKey; @endphp
          <button type="button" class="sched__day {{ $isActive ? 'is-active' : '' }}"
                  data-day="{{ $day['key'] }}" role="tab" aria-selected="{{ $isActive ? 'true' : 'false' }}">
            <span class="sched__day-name">{{ $day['name'] }}</span>
            <span class="sched__day-date">{{ $day['date'] }}</span>
          </button>
        @endforeach
      </div>

      <div class="sched__board reveal">
        @foreach($week as $day)
          <div class="sched__panel {{ $day['key'] === $activeDayKey ? '' : 'is-hidden' }}" data-panel="{{ $day['key'] }}">
            @forelse($day['slots'] as $slot)
              @php
                $cls = $slot['status'] === 'full' ? 'slot--full' : ($slot['status'] === 'cancelled' ? 'slot--cancelled' : '');
              @endphp
              <div class="slot slot--trainer {{ $cls }}">
                <div class="slot__time">{{ $slot['time'] }}</div>
                <div class="slot__main">
                  <h3 class="slot__title">{{ $slot['direction'] ?: $slot['topic'] ?: $slot['title'] }}</h3>
                  @if(!empty($slot['direction']) && !empty($slot['topic']))
                    <p class="slot__topic">{{ $slot['topic'] }}</p>
                  @endif
                  <p class="slot__meta">
                    <span class="badge badge--{{ $slot['type'] }}">{{ $typeLabels[$slot['type']] ?? $slot['type_label'] }}</span>
                    <span class="slot__trainer">{{ $slot['taken'] }} из {{ $slot['total'] }} записано</span>
                  </p>
                  @if($slot['status'] === 'cancelled')
                    <p class="trainer__cancel-reason">Причина отмены: {{ $slot['reason'] }}</p>
                  @elseif(count($slot['attendees']))
                    <ul class="trainer__roster">
                      @foreach($slot['attendees'] as $guest)
                        <li>
                          {{ $guest['name'] }}
                          @if(!empty($guest['health_note']))
                            <span class="trainer__health-note">{{ $guest['health_note'] }}</span>
                          @endif
                        </li>
                      @endforeach
                    </ul>
                  @else
                    <p class="trainer__empty-roster">Пока никто не записан</p>
                  @endif
                </div>
                <div class="slot__seats">
                  @if($slot['status'] === 'cancelled')
                    <span class="slot__seats-num slot__seats-num--off">Отменено</span>
                  @else
                    <span class="slot__seats-num">{{ $slot['taken'] }}</span>
                    <span class="slot__seats-label">записано</span>
                  @endif
                </div>
              </div>
            @empty
              <p class="sched__empty">В этот день у вас нет занятий.</p>
            @endforelse
          </div>
        @endforeach
      </div>
    </div>
  </section>
@endsection
