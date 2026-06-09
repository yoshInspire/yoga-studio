@extends('layouts.site')

@section('title', 'Личный кабинет — Студия йоги Ирины Коленцевой')

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
            <a href="{{ route('purchase.index') }}" class="lk__navlink lk__navlink--buy">Купить абонемент</a>
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
            @if (session('status'))
              <div class="auth__alert auth__alert--ok" style="margin-bottom: 18px">{{ session('status') }}</div>
            @endif
            @if ($errors->has('telegram'))
              <div class="auth__alert auth__alert--error" style="margin-bottom: 18px">{{ $errors->first('telegram') }}</div>
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
              <div class="lk__field">
                <dt>Telegram</dt>
                <dd>{{ $user->telegramDisplayAccount() ?? 'Не привязан' }}</dd>
              </div>
            </dl>

            <div class="lk__telegram">
              @if ($user->hasTelegram())
                <p class="lk__lead">Вход через Telegram доступен для вашего аккаунта.</p>
                <form action="{{ route('account.telegram.unlink') }}" method="post" class="lk__telegram-actions">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="btn btn--ghost">Отвязать Telegram</button>
                </form>
              @elseif ($telegramEnabled ?? false)
                <p class="lk__lead">Привяжите Telegram, чтобы входить на сайт без пароля.</p>
                @include('partials.telegram-login-widget', [
                  'telegramEnabled' => $telegramEnabled,
                  'telegramBotUsername' => $telegramBotUsername,
                  'telegramAuthUrl' => route('account.telegram.callback'),
                ])
              @else
                <p class="lk__lead">Привязка Telegram скоро будет доступна.</p>
              @endif
            </div>

            <button type="button" class="btn btn--line" data-soon="Редактирование профиля в личном кабинете скоро появится. Пока изменить данные можно через администратора студии — напишите или позвоните нам.">Редактировать профиль</button>
          </div>

          {{-- Абонементы --}}
          <div class="lk__panel is-hidden" data-panel="subs">
            <h1 class="lk__title">Мои абонементы</h1>
            <p class="lk__lead">Групповой абонемент нельзя тратить на индивидуальное занятие и наоборот — при записи подходящий тип выбирается автоматически.</p>
            <div class="subs">
              @forelse($subscriptions as $sub)
                @php
                  $left = $sub->sessionsRemaining();
                  $pct = $sub->sessions_total ? round($left / $sub->sessions_total * 100) : 0;
                @endphp
                <div class="sub {{ $sub->isActive() ? '' : 'sub--inactive' }}">
                  <div class="sub__head">
                    <span class="badge badge--{{ $sub->type->badgeClass() }}">{{ $sub->type->shortLabel() }}</span>
                    <h3 class="sub__name">{{ $sub->type->label() }}</h3>
                    @unless($sub->isActive())
                      <span class="sub__status">Не активен</span>
                    @endunless
                  </div>
                  <div class="sub__count"><strong>{{ $left }}</strong> из {{ $sub->sessions_total }} занятий осталось</div>
                  <div class="sub__bar"><span style="width: {{ $pct }}%"></span></div>
                  <dl class="sub__dates">
                    <div><dt>Начало</dt><dd>{{ $sub->formattedStartsAt() }}</dd></div>
                    <div><dt>Действует до</dt><dd>{{ $sub->formattedEndsAt() }}</dd></div>
                  </dl>
                </div>
              @empty
                <p class="lk__empty">У вас пока нет абонементов. <a href="{{ route('purchase.index') }}">Купите абонемент онлайн</a> или уточните в студии.</p>
              @endforelse
            </div>
          </div>

          {{-- Мои записи --}}
          <div class="lk__panel is-hidden" data-panel="bookings">
            <h1 class="lk__title">Мои записи</h1>
            <p class="lk__lead">Записи на ближайшую неделю. Отменить без списания занятия можно не позднее чем за 4 часа до начала.</p>
            @if ($errors->has('booking'))
              <div class="auth__alert auth__alert--error" style="margin-bottom: 16px">{{ $errors->first('booking') }}</div>
            @endif
            <div class="lk-list">
              @forelse($bookings as $booking)
                @php $session = $booking->classSession; @endphp
                <div class="lk-row">
                  <div class="lk-row__when">
                    <strong>{{ $session->formattedTime() }}</strong>
                    <span>{{ $session->starts_at->translatedFormat('D, j F') }}</span>
                  </div>
                  <div class="lk-row__main">
                    <h3>{{ $session->title }}</h3>
                    <p>
                      <span class="badge badge--{{ $session->type->badgeClass() }}">{{ $session->type->shortLabel() }}</span>
                      {{ $session->trainerName() }}
                    </p>
                  </div>
                  @if($booking->canBeCancelledByClient())
                    <form action="{{ route('bookings.cancel', $booking) }}" method="post">
                      @csrf
                      <button type="submit" class="btn btn--ghost">Отменить</button>
                    </form>
                  @else
                    <button type="button" class="btn btn--ghost" disabled title="Отмена менее чем за 4 часа недоступна">Отменить</button>
                  @endif
                </div>
              @empty
                <p class="lk__empty">Пока нет активных записей. <a href="{{ route('schedule') }}">Посмотреть расписание</a></p>
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
                @forelse($history as $h)
                  <tr><td>{{ $h['date'] }}</td><td>{{ $h['title'] }}</td><td>{{ $h['sub'] }}</td></tr>
                @empty
                  <tr><td colspan="3" class="lk__empty">История появится после посещений.</td></tr>
                @endforelse
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
              @if ($offerAvailable)
                <a href="{{ route('offer.show') }}" target="_blank" rel="noopener" class="btn btn--solid">Открыть оферту</a>
              @else
                <button type="button" class="btn btn--solid" data-soon="Договор-оферта появится здесь, как только студия загрузит документ. Загляните чуть позже.">Открыть оферту</button>
              @endif
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
@endsection
