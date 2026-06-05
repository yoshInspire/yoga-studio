@extends('layouts.site')

@section('title', 'Личный кабинет — Студия йоги Ирины Коленцевой')

@php
  // Абонементы, записи и история — демо до блоков 4–5.
  $subs = [
    ['name' => 'Групповые занятия', 'type' => 'group', 'left' => 6, 'total' => 8, 'start' => '20 мая 2026', 'end' => '20 июля 2026'],
    ['name' => 'Индивидуальные занятия', 'type' => 'indiv', 'left' => 2, 'total' => 4, 'start' => '01 июня 2026', 'end' => '01 августа 2026'],
  ];
  $bookings = [
    ['date' => 'Ср, 5 июня', 'time' => '08:00', 'title' => 'Хатха-йога', 'trainer' => 'Ирина Коленцева', 'type' => 'Групповое'],
    ['date' => 'Пт, 7 июня', 'time' => '12:00', 'title' => 'Индивидуальное занятие', 'trainer' => 'Ирина Коленцева', 'type' => 'Индивидуальное'],
  ];
  $history = [
    ['date' => '28 мая 2026', 'title' => 'Инь-йога', 'sub' => 'Групповой абонемент'],
    ['date' => '24 мая 2026', 'title' => 'Здоровая спина', 'sub' => 'Групповой абонемент'],
    ['date' => '21 мая 2026', 'title' => 'Индивидуальное занятие', 'sub' => 'Индивидуальный абонемент'],
  ];
  $cancelled = [
    ['date' => 'Пт, 7 июня · 18:00', 'title' => 'Инь-йога', 'reason' => 'Недостаточное количество участников в группе'],
  ];
@endphp

@section('content')
  <section class="section lk">
    <div class="container">
      <div class="lk__grid reveal">
        {{-- Боковая навигация --}}
        <aside class="lk__aside">
          <div class="lk__user">
            <span class="lk__avatar">{{ $user->initials() }}</span>
            <div>
              <strong class="lk__user-name">{{ $user->shortName() }}</strong>
              <span class="lk__user-role">Клиент студии</span>
            </div>
          </div>
          <nav class="lk__nav" id="lkNav">
            <button type="button" class="lk__navlink is-active" data-sec="profile">Профиль</button>
            <button type="button" class="lk__navlink" data-sec="subs">Мои абонементы</button>
            <button type="button" class="lk__navlink" data-sec="bookings">Мои записи</button>
            <button type="button" class="lk__navlink" data-sec="history">История посещений</button>
            <button type="button" class="lk__navlink" data-sec="cancelled">Отменённые занятия</button>
            <button type="button" class="lk__navlink" data-sec="oferta">Договор-оферта</button>
            <a href="{{ route('schedule') }}" class="lk__navlink lk__navlink--out">Расписание и запись</a>
            <form action="{{ route('logout') }}" method="post" class="lk__logout">
              @csrf
              <button type="submit" class="lk__navlink lk__navlink--exit">Выйти</button>
            </form>
          </nav>
        </aside>

        {{-- Контент --}}
        <div class="lk__content">
          {{-- Профиль --}}
          <div class="lk__panel" data-panel="profile">
            <h1 class="lk__title">Профиль</h1>
            <p class="lk__lead">Данные из регистрации. Их можно изменить — или попросить администратора.</p>
            @if (session('status'))
              <div class="auth__alert auth__alert--ok" style="margin-bottom: 18px">{{ session('status') }}</div>
            @endif
            <dl class="lk__fields">
              <div class="lk__field"><dt>Имя</dt><dd>{{ $user->first_name }}</dd></div>
              <div class="lk__field"><dt>Фамилия</dt><dd>{{ $user->last_name }}</dd></div>
              @if ($user->patronymic)
                <div class="lk__field"><dt>Отчество</dt><dd>{{ $user->patronymic }}</dd></div>
              @endif
              <div class="lk__field"><dt>Дата рождения</dt><dd>{{ $user->formattedBirthDate() ?? '—' }}</dd></div>
              <div class="lk__field"><dt>Телефон</dt><dd>{{ $user->formattedPhone() ?? '—' }}</dd></div>
              @if ($user->email)
                <div class="lk__field"><dt>Email</dt><dd>{{ $user->email }}</dd></div>
              @endif
            </dl>
            <button type="button" class="btn btn--line">Редактировать профиль</button>
          </div>

          {{-- Абонементы --}}
          <div class="lk__panel is-hidden" data-panel="subs">
            <h1 class="lk__title">Мои абонементы</h1>
            <p class="lk__lead">Групповой абонемент нельзя тратить на индивидуальное занятие и наоборот — при записи подходящий тип выбирается автоматически.</p>
            <div class="subs">
              @foreach($subs as $sub)
                @php $pct = $sub['total'] ? round($sub['left'] / $sub['total'] * 100) : 0; @endphp
                <div class="sub">
                  <div class="sub__head">
                    <span class="badge badge--{{ $sub['type'] }}">{{ $sub['type'] === 'group' ? 'Групповой' : 'Индивидуальный' }}</span>
                    <h3 class="sub__name">{{ $sub['name'] }}</h3>
                  </div>
                  <div class="sub__count"><strong>{{ $sub['left'] }}</strong> из {{ $sub['total'] }} занятий осталось</div>
                  <div class="sub__bar"><span style="width: {{ $pct }}%"></span></div>
                  <dl class="sub__dates">
                    <div><dt>Начало</dt><dd>{{ $sub['start'] }}</dd></div>
                    <div><dt>Действует до</dt><dd>{{ $sub['end'] }}</dd></div>
                  </dl>
                </div>
              @endforeach
            </div>
          </div>

          {{-- Мои записи --}}
          <div class="lk__panel is-hidden" data-panel="bookings">
            <h1 class="lk__title">Мои записи</h1>
            <p class="lk__lead">Записи на ближайшую неделю. Отменить без списания занятия можно не позднее чем за 4 часа до начала.</p>
            <div class="lk-list">
              @forelse($bookings as $b)
                <div class="lk-row">
                  <div class="lk-row__when">
                    <strong>{{ $b['time'] }}</strong>
                    <span>{{ $b['date'] }}</span>
                  </div>
                  <div class="lk-row__main">
                    <h3>{{ $b['title'] }}</h3>
                    <p><span class="badge badge--{{ $b['type'] === 'Индивидуальное' ? 'indiv' : 'group' }}">{{ $b['type'] }}</span> {{ $b['trainer'] }}</p>
                  </div>
                  <button type="button" class="btn btn--ghost">Отменить</button>
                </div>
              @empty
                <p class="lk__empty">Пока нет активных записей.</p>
              @endforelse
            </div>
            <a href="{{ route('schedule') }}" class="btn btn--solid" style="margin-top: 6px">Записаться на занятие</a>
          </div>

          {{-- История --}}
          <div class="lk__panel is-hidden" data-panel="history">
            <h1 class="lk__title">История посещений</h1>
            <p class="lk__lead">Занятия, которые вы посетили, и с какого абонемента они списаны.</p>
            <table class="lk-table">
              <thead><tr><th>Дата</th><th>Занятие</th><th>Списано с</th></tr></thead>
              <tbody>
                @foreach($history as $h)
                  <tr><td>{{ $h['date'] }}</td><td>{{ $h['title'] }}</td><td>{{ $h['sub'] }}</td></tr>
                @endforeach
              </tbody>
            </table>
          </div>

          {{-- Отменённые --}}
          <div class="lk__panel is-hidden" data-panel="cancelled">
            <h1 class="lk__title">Отменённые занятия</h1>
            <p class="lk__lead">Занятия, отменённые студией, и причина отмены.</p>
            <div class="lk-list">
              @forelse($cancelled as $c)
                <div class="lk-row lk-row--cancelled">
                  <div class="lk-row__main">
                    <h3>{{ $c['title'] }}</h3>
                    <p class="lk-row__when-inline">{{ $c['date'] }}</p>
                    <p class="lk-row__reason">Причина: {{ $c['reason'] }}</p>
                  </div>
                  <span class="lk-row__status">Отменено</span>
                </div>
              @empty
                <p class="lk__empty">Отменённых занятий нет.</p>
              @endforelse
            </div>
          </div>

          {{-- Оферта --}}
          <div class="lk__panel is-hidden" data-panel="oferta">
            <h1 class="lk__title">Договор-оферта</h1>
            <p class="lk__lead">Публичная оферта студии. Документ доступен для просмотра в кабинете.</p>
            <div class="oferta">
              <span class="oferta__icon">PDF</span>
              <div class="oferta__info">
                <strong>Договор публичной оферты</strong>
                <span>Просмотр в защищённом режиме, без прямого скачивания.</span>
              </div>
              <button type="button" class="btn btn--solid">Открыть оферту</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
@endsection
