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
          <nav class="lk__nav" id="lkNav" data-initial-section="{{ $lkSection ?? '' }}">
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
          @php
            $profileMonths = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
            $profileEditing = (bool) ($profileEditOpen ?? false);
            $profilePatronymic = old('patronymic', $user->patronymic);
          @endphp
          <div class="lk__panel" data-panel="profile">
            <h1 class="lk__title">Профиль</h1>
            @if (session('status'))
              <div class="auth__alert auth__alert--ok lk__alert">{{ session('status') }}</div>
            @endif
            @if ($errors->has('telegram'))
              <div class="auth__alert auth__alert--error lk__alert">{{ $errors->first('telegram') }}</div>
            @endif

            <div id="profileView" class="{{ $profileEditing ? 'is-hidden' : '' }}">
              <dl class="lk__fields">
                <div class="lk__field"><dt>Имя</dt><dd>{{ $user->first_name }}</dd></div>
                <div class="lk__field"><dt>Фамилия</dt><dd>{{ $user->last_name }}</dd></div>
                @if ($user->patronymic)
                  <div class="lk__field"><dt>Отчество</dt><dd>{{ $user->patronymic }}</dd></div>
                @endif
                <div class="lk__field"><dt>Дата рождения</dt><dd>{{ $user->formattedBirthDate() ?? '—' }}</dd></div>
                <div class="lk__field"><dt>Телефон</dt><dd>{{ $user->formattedPhone() ?? '—' }}</dd></div>
                <div class="lk__field"><dt>Email</dt><dd>{{ $user->email ?? '—' }}</dd></div>
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
                  <p class="lk__lead">Привяжите Telegram, чтобы входить на сайт без пароля и не пропускать важные новости студии.</p>
                  @include('partials.telegram-login-widget', [
                    'telegramEnabled' => $telegramEnabled,
                    'telegramBotUsername' => $telegramBotUsername,
                    'telegramAuthUrl' => route('account.telegram.callback'),
                  ])
                @else
                  <p class="lk__lead">Привязка Telegram скоро будет доступна.</p>
                @endif
              </div>

              <button type="button" class="btn btn--line" id="profileEditBtn">Редактировать профиль</button>
            </div>

            <div id="profileEdit" class="lk__profile-edit {{ $profileEditing ? '' : 'is-hidden' }}">
              @if ($errors->getBag('profile')->any())
                <div class="auth__alert auth__alert--error lk__alert">
                  @foreach ($errors->getBag('profile')->all() as $error)
                    <p>{{ $error }}</p>
                  @endforeach
                </div>
              @endif

              <form
                id="profileEditForm"
                class="lk__profile-form auth__form"
                action="{{ route('account.profile.update') }}"
                method="post"
                data-original-email="{{ mb_strtolower(trim((string) $user->email)) }}"
                data-verified-email="{{ $profileEmailVerified ? mb_strtolower(trim((string) $profileEmailVerified)) : '' }}"
                data-pending-email="{{ $profilePendingEmail ? mb_strtolower(trim((string) $profilePendingEmail)) : '' }}"
                data-code-sent="{{ ($profileCodeSent ?? false) ? '1' : '0' }}"
              >
                @csrf
                @method('PUT')

                <div class="auth__row2">
                  <div class="form__row">
                    <label class="auth__label" for="profile-first-name">Имя</label>
                    <input type="text" id="profile-first-name" name="first_name" value="{{ old('first_name', $user->first_name) }}" autocomplete="given-name" required />
                  </div>
                  <div class="form__row">
                    <label class="auth__label" for="profile-last-name">Фамилия</label>
                    <input type="text" id="profile-last-name" name="last_name" value="{{ old('last_name', $user->last_name) }}" autocomplete="family-name" required />
                  </div>
                </div>

                <label class="auth__check auth__check--block lk__patronymic-toggle">
                  <input type="checkbox" id="profile-patronymic-toggle" @checked($profilePatronymic) /> Указать отчество
                </label>
                <div class="form__row auth__patronymic {{ $profilePatronymic ? '' : 'is-hidden' }}" id="profile-patronymic-field">
                  <label class="auth__label" for="profile-patronymic">Отчество</label>
                  <input type="text" id="profile-patronymic" name="patronymic" value="{{ $profilePatronymic }}" autocomplete="additional-name" />
                </div>

                <label class="auth__label">Дата рождения</label>
                <div class="auth__row3 auth__birth">
                  <div class="form__row">
                    <div class="auth__select-wrap">
                      <select name="birth_day" class="auth__select" aria-label="День" required>
                        <option value="">День</option>
                        @for ($d = 1; $d <= 31; $d++)
                          <option value="{{ $d }}" @selected((int) old('birth_day', $user->birth_day) === $d)>{{ $d }}</option>
                        @endfor
                      </select>
                    </div>
                  </div>
                  <div class="form__row">
                    <div class="auth__select-wrap">
                      <select name="birth_month" class="auth__select auth__select--month" aria-label="Месяц" required>
                        <option value="">Месяц</option>
                        @foreach ($profileMonths as $idx => $m)
                          <option value="{{ $idx + 1 }}" @selected((int) old('birth_month', $user->birth_month) === $idx + 1)>{{ $m }}</option>
                        @endforeach
                      </select>
                    </div>
                  </div>
                  <div class="form__row">
                    <input type="number" name="birth_year" class="auth__input-year" value="{{ old('birth_year', $user->birth_year) }}" placeholder="Год" min="1920" max="2026" aria-label="Год рождения" />
                  </div>
                </div>

                <div class="form__row">
                  <label class="auth__label" for="profile-phone">Телефон</label>
                  <input type="tel" id="profile-phone" name="phone" value="{{ old('phone', $user->formattedPhone()) }}" autocomplete="tel" inputmode="tel" data-phone-mask required />
                </div>

                <div class="form__row">
                  <label class="auth__label" for="profile-email">Email <span class="auth__optional">(необязательно)</span></label>
                  <input type="email" id="profile-email" name="email" value="{{ old('email', $user->email) }}" autocomplete="email" />
                  <div id="profileEmailVerify" class="lk__email-verify is-hidden">
                    <p class="lk__email-verify-lead" id="profileEmailVerifyLead">
                      @if ($profilePendingEmail)
                        Код отправлен на <strong>{{ $profilePendingEmail }}</strong>. Введите его ниже.
                      @else
                        Для сохранения нового email нужно подтвердить адрес.
                      @endif
                    </p>
                    <button type="button" class="btn btn--ghost lk__email-send" id="profileSendCodeBtn">Отправить код на email</button>
                    <div class="lk__email-verify-row {{ ($profileCodeSent ?? false) || $profilePendingEmail ? '' : 'is-hidden' }}" id="profileCodeRow">
                      <input
                        type="text"
                        id="profile-email-code"
                        placeholder="000000"
                        inputmode="numeric"
                        pattern="\d{6}"
                        maxlength="6"
                        autocomplete="one-time-code"
                        class="auth__code-input lk__email-code"
                        value="{{ old('code') }}"
                      />
                      <button type="button" class="btn btn--ghost" id="profileVerifyEmailBtn">Подтвердить email</button>
                    </div>
                  </div>
                  <p id="profileEmailVerified" class="lk__email-verified is-hidden">Email подтверждён — можно сохранить изменения.</p>
                </div>

                <div class="lk__profile-actions">
                  <button type="submit" class="btn btn--solid" id="profileSaveBtn">Сохранить изменения</button>
                  <button type="button" class="btn btn--ghost" id="profileCancelBtn">Отмена</button>
                </div>
              </form>

              <form id="profileEmailSendForm" action="{{ route('account.profile.email.send') }}" method="post" hidden>
                @csrf
              </form>
              <form id="profileEmailVerifyForm" action="{{ route('account.profile.email.verify') }}" method="post" hidden>
                @csrf
              </form>
            </div>
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
