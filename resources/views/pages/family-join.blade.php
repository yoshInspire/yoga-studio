@extends('layouts.site')

@section('title', 'Приглашение в семью — Студия йоги Ирины Коленцевой')
@section('robots', 'noindex, nofollow')

@section('content')
  <section class="section lk">
    <div class="container">
      <div class="lk__content reveal family-join">
        @if ($invite === null)
          <div class="payment-result__icon payment-result__icon--fail" aria-hidden="true">×</div>
          <h1 class="lk__title">Приглашение не найдено</h1>
          <p class="lk__lead">Проверьте ссылку или попросите прислать новую.</p>
        @else
          <div class="family-join__avatar" aria-hidden="true">{{ $preview['inviter_initials'] }}</div>
          <h1 class="lk__title">{{ $preview['inviter_name'] }} приглашает вас в семью</h1>
          <p class="lk__lead">
            В семье владелец абонемента может разрешить остальным записываться на занятия по нему —
            занятие спишется с его абонемента. Сама семья ничего не тратит: доступ к каждому абонементу
            владелец открывает отдельно и может закрыть в любой момент.
          </p>

          <ul class="family-join__facts">
            <li>Участники видят друг у друга только имя и фото — телефон, почта и заметки о здоровье остаются скрытыми.</li>
            <li>Владелец абонемента видит, кто и на какое занятие записался по его абонементу.</li>
            <li>Выйти из семьи можно в любой момент в личном кабинете.</li>
            <li>Сейчас в семье: {{ implode(', ', $preview['members']) }}.</li>
          </ul>

          @if ($errors->has('family'))
            <div class="auth__alert auth__alert--error lk__alert">{{ $errors->first('family') }}</div>
          @endif

          @if ($user === null)
            <div class="family-join__actions">
              <a href="{{ route('login') }}" class="btn btn--solid">Войти и вступить</a>
              <a href="{{ $appUrl }}" class="btn btn--line">Открыть в приложении</a>
            </div>
            <p class="family-join__hint">
              Ещё нет аккаунта? Зарегистрируйтесь на странице входа — после этого вернитесь по ссылке из приглашения.
              Код приглашения: <strong>{{ $preview['code'] }}</strong>, действует до {{ $preview['expires_at'] }}.
            </p>
          @elseif ($preview['blocker'] !== null)
            <div class="auth__alert auth__alert--error lk__alert">{{ $preview['blocker'] }}</div>
            <div class="family-join__actions">
              <a href="{{ route('account') }}" class="btn btn--line">В личный кабинет</a>
            </div>
          @else
            <form action="{{ route('family.accept', ['code' => $invite->code]) }}" method="post" class="family-join__actions">
              @csrf
              <button type="submit" class="btn btn--solid">Вступить в семью</button>
              <a href="{{ route('account') }}" class="btn btn--ghost">Не сейчас</a>
            </form>
            <p class="family-join__hint">Приглашение действует до {{ $preview['expires_at'] }}.</p>
          @endif
        @endif
      </div>
    </div>
  </section>
@endsection
