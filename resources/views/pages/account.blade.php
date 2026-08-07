@extends('layouts.site')

@section('title', 'Личный кабинет — Студия йоги Ирины Коленцевой')
@section('robots', 'noindex, nofollow')

@section('content')
  <section class="section lk">
    <div class="container">
      <div class="lk__grid reveal">
        {{-- Боковая навигация --}}
        <aside class="lk__aside">
          <div class="lk__user">
            @include('partials.avatar-editor', ['avatarUser' => $user])
            <div>
              <strong class="lk__user-name">{{ $user->shortName() }}</strong>
              <span class="lk__user-role">Клиент студии</span>
              <button type="button" class="lk__user-photo" data-avatar-open>
                {{ $user->hasAvatar() ? 'Сменить фото' : 'Добавить фото' }}
              </button>
            </div>
          </div>
          @if ($errors->has('photo'))
            <p class="avatar__error">{{ $errors->first('photo') }}</p>
          @endif
          <nav class="lk__nav" id="lkNav" data-initial-section="{{ $lkSection ?? '' }}">
            <button type="button" class="lk__navlink is-active" data-sec="profile">Профиль</button>
            <button type="button" class="lk__navlink" data-sec="subs">Мои абонементы</button>
            <a href="{{ route('purchase.index') }}" class="lk__navlink lk__navlink--buy">Купить абонемент</a>
            <button type="button" class="lk__navlink" data-sec="bookings">Мои бронирования</button>
            <button type="button" class="lk__navlink" data-sec="history">История посещений</button>
            <button type="button" class="lk__navlink" data-sec="cancelled">Отменённые занятия</button>
            <button type="button" class="lk__navlink" data-sec="oferta">Договор-оферта</button>
            <a href="{{ route('schedule') }}" class="lk__navlink lk__navlink--out">Расписание и бронирование</a>
            <form action="{{ route('logout') }}" method="post" class="lk__logout">
              @csrf
              <button type="submit" class="lk__navlink lk__navlink--exit">Выйти</button>
            </form>
          </nav>
        </aside>

        {{-- Контент --}}
        <div class="lk__content">
          @if (! $user->hasAcceptedOffer())
            <div class="auth__alert auth__alert--error lk__alert">
              <p>Чтобы бронировать места и покупать абонементы, примите договор-оферту в разделе «Договор-оферта».</p>
            </div>
          @endif
          {{-- Профиль --}}
          @php
            $profileMonths = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
            // ?delete=1 приходит со страницы «Удаление аккаунта»: там кнопка ведёт
            // прямо к форме, а она живёт в режиме редактирования профиля.
            $profileEditing = (bool) ($profileEditOpen ?? false)
                || $errors->getBag('password')->any()
                || $errors->getBag('deleteAccount')->any()
                || request()->boolean('delete');
            $profilePatronymic = old('patronymic', $user->patronymic);
          @endphp
          <div class="lk__panel" data-panel="profile">
            <h1 class="lk__title" id="profilePanelTitle">{{ $profileEditing ? 'Редактирование профиля' : 'Профиль' }}</h1>
            @if (session('status'))
              <div class="auth__alert auth__alert--ok lk__alert">{{ session('status') }}</div>
            @endif
            @if ($errors->has('telegram'))
              <div class="auth__alert auth__alert--error lk__alert">{{ $errors->first('telegram') }}</div>
            @endif

            <div id="profileView" class="lk-profile-pane {{ $profileEditing ? 'is-hidden' : '' }}">
              <dl class="lk__fields">
                <div class="lk__field"><dt>Имя</dt><dd>{{ $user->first_name }}</dd></div>
                <div class="lk__field"><dt>Фамилия</dt><dd>{{ $user->last_name }}</dd></div>
                @if ($user->patronymic)
                  <div class="lk__field"><dt>Отчество</dt><dd>{{ $user->patronymic }}</dd></div>
                @endif
                <div class="lk__field"><dt>Дата рождения</dt><dd>{{ $user->formattedBirthDate() ?? '—' }}</dd></div>
                <div class="lk__field"><dt>Телефон</dt><dd>{{ $user->formattedPhone() ?? '—' }}</dd></div>
                <div class="lk__field"><dt>Email</dt><dd>{{ $user->email ?? '—' }}</dd></div>
                <div class="lk__field lk__field--telegram">
                  <dt>Telegram</dt>
                  <dd>
                    @if ($user->hasTelegram())
                      <div class="lk-tg-row">
                        <span class="lk-tg-row__name">{{ $user->telegramDisplayAccount() }}</span>
                        <form action="{{ route('account.telegram.unlink') }}" method="post" class="lk-tg-row__form">
                          @csrf
                          @method('DELETE')
                          <button type="submit" class="lk-tg-unlink">Отвязать</button>
                        </form>
                      </div>
                    @else
                      <div class="lk-tg-row">
                        <span class="lk-tg-row__empty">Не привязан</span>
                        @if ($telegramEnabled ?? false)
                          <div class="lk-tg-row__widget">
                            @include('partials.telegram-login-widget', [
                              'telegramEnabled' => $telegramEnabled,
                              'telegramBotUsername' => $telegramBotUsername,
                              'telegramAuthUrl' => route('account.telegram.callback'),
                              'telegramWidgetSize' => 'medium',
                            ])
                          </div>
                        @endif
                      </div>
                    @endif
                    @if ($telegramEnabled ?? false)
                      <p class="lk-tg-hint">
                        После привязки вы будете получать уведомления и коды сброса пароля в Telegram. Сначала откройте бота
                        @if (!empty($telegramBotUsername))
                          <a href="https://t.me/{{ $telegramBotUsername }}" target="_blank" rel="noopener">@<span>{{ $telegramBotUsername }}</span></a>
                        @else
                          студии
                        @endif
                        и нажмите «Старт». Без этого бот не сможет написать вам первым.
                      </p>
                    @endif
                  </dd>
                </div>
              </dl>

              <button type="button" class="btn btn--line" id="profileEditBtn">Редактировать профиль</button>
            </div>

            <div id="profileEdit" class="lk-profile-pane lk-profile-edit {{ $profileEditing ? '' : 'is-hidden' }}">
              @if ($errors->getBag('profile')->any())
                <div class="auth__alert auth__alert--error lk__alert">
                  @foreach ($errors->getBag('profile')->all() as $error)
                    <p>{{ $error }}</p>
                  @endforeach
                </div>
              @endif

              <div class="lk-profile-form">
              <form
                id="profileEditForm"
                action="{{ route('account.profile.update') }}"
                method="post"
                data-original-email="{{ mb_strtolower(trim((string) $user->email)) }}"
                data-verified-email="{{ $profileEmailVerified ? mb_strtolower(trim((string) $profileEmailVerified)) : '' }}"
                data-pending-email="{{ $profilePendingEmail ? mb_strtolower(trim((string) $profilePendingEmail)) : '' }}"
                data-code-sent="{{ ($profileCodeSent ?? false) ? '1' : '0' }}"
              >
                @csrf
                @method('PUT')

                <div class="lk-profile-form__section">
                  <h2 class="lk-profile-form__heading">Личные данные</h2>
                  <div class="lk-profile-form__grid lk-profile-form__grid--2">
                    <div class="lk-profile-form__field">
                      <label class="lk-profile-form__label" for="profile-first-name">Имя</label>
                      <input class="lk-profile-form__input" type="text" id="profile-first-name" name="first_name" value="{{ old('first_name', $user->first_name) }}" autocomplete="given-name" required />
                    </div>
                    <div class="lk-profile-form__field">
                      <label class="lk-profile-form__label" for="profile-last-name">Фамилия</label>
                      <input class="lk-profile-form__input" type="text" id="profile-last-name" name="last_name" value="{{ old('last_name', $user->last_name) }}" autocomplete="family-name" required />
                    </div>
                  </div>

                  <label class="lk-profile-form__check">
                    <input type="checkbox" id="profile-patronymic-toggle" @checked($profilePatronymic) />
                    <span>Указать отчество</span>
                  </label>
                  <div class="lk-profile-form__field lk-profile-form__patronymic {{ $profilePatronymic ? '' : 'is-hidden' }}" id="profile-patronymic-field">
                    <label class="lk-profile-form__label" for="profile-patronymic">Отчество</label>
                    <input class="lk-profile-form__input" type="text" id="profile-patronymic" name="patronymic" value="{{ $profilePatronymic }}" autocomplete="additional-name" />
                  </div>
                </div>

                <div class="lk-profile-form__section">
                  <h2 class="lk-profile-form__heading">Дата рождения</h2>
                  <div class="lk-profile-form__birth">
                    <div class="lk-profile-form__field">
                      <label class="lk-profile-form__label" for="profile-birth-day">День</label>
                      <div class="lk-profile-form__select-wrap">
                        <select class="lk-profile-form__select" id="profile-birth-day" name="birth_day" required>
                          <option value="">День</option>
                          @for ($d = 1; $d <= 31; $d++)
                            <option value="{{ $d }}" @selected((int) old('birth_day', $user->birth_day) === $d)>{{ $d }}</option>
                          @endfor
                        </select>
                      </div>
                    </div>
                    <div class="lk-profile-form__field">
                      <label class="lk-profile-form__label" for="profile-birth-month">Месяц</label>
                      <div class="lk-profile-form__select-wrap">
                        <select class="lk-profile-form__select" id="profile-birth-month" name="birth_month" required>
                          <option value="">Месяц</option>
                          @foreach ($profileMonths as $idx => $m)
                            <option value="{{ $idx + 1 }}" @selected((int) old('birth_month', $user->birth_month) === $idx + 1)>{{ $m }}</option>
                          @endforeach
                        </select>
                      </div>
                    </div>
                    <div class="lk-profile-form__field">
                      <label class="lk-profile-form__label" for="profile-birth-year">Год</label>
                      <input class="lk-profile-form__input" type="number" id="profile-birth-year" name="birth_year" value="{{ old('birth_year', $user->birth_year) }}" placeholder="Год" min="1920" max="2026" />
                    </div>
                  </div>
                </div>

                <div class="lk-profile-form__section">
                  <h2 class="lk-profile-form__heading">Контакты</h2>
                  <div class="lk-profile-form__field">
                    <label class="lk-profile-form__label" for="profile-phone">Телефон</label>
                    <input class="lk-profile-form__input" type="tel" id="profile-phone" name="phone" value="{{ old('phone', $user->formattedPhone()) }}" autocomplete="tel" inputmode="tel" data-phone-mask required />
                  </div>
                  <div class="lk-profile-form__field">
                    <label class="lk-profile-form__label" for="profile-email">Email</label>
                    <input class="lk-profile-form__input" type="email" id="profile-email" name="email" value="{{ old('email', $user->email) }}" autocomplete="email" placeholder="Необязательно" />
                    <div id="profileEmailVerify" class="lk-profile-email is-hidden">
                      <button type="button" class="btn btn--line lk-profile-email__send" id="profileSendCodeBtn">Отправить код</button>
                      <div class="lk-profile-email__code {{ ($profileCodeSent ?? false) || $profilePendingEmail ? '' : 'is-hidden' }}" id="profileCodeRow">
                        <label class="lk-profile-form__label" for="profile-email-code">Код из письма</label>
                        <div class="lk-profile-email__code-row">
                          <input
                            type="text"
                            id="profile-email-code"
                            placeholder="000000"
                            inputmode="numeric"
                            pattern="\d{6}"
                            maxlength="6"
                            autocomplete="one-time-code"
                            class="lk-profile-form__code"
                            value="{{ old('code') }}"
                          />
                          <button type="button" class="btn btn--ghost" id="profileVerifyEmailBtn">Подтвердить</button>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="lk-profile-form__actions">
                  <button type="submit" class="btn btn--solid" id="profileSaveBtn">Сохранить</button>
                  <button type="button" class="btn btn--ghost" id="profileCancelBtn">Отмена</button>
                </div>
              </form>

              <div class="lk-profile-form__section">
                <h2 class="lk-profile-form__heading">Смена пароля</h2>
                @if ($errors->getBag('password')->any())
                  <div class="auth__alert auth__alert--error lk__alert">
                    @foreach ($errors->getBag('password')->all() as $error)
                      <p>{{ $error }}</p>
                    @endforeach
                  </div>
                @endif
                <form id="profilePasswordForm" action="{{ route('account.password.update') }}" method="post" hidden>
                  @csrf
                  @method('PUT')
                </form>
                <div class="lk-profile-form__field">
                  <label class="lk-profile-form__label" for="profile-current-password" form="profilePasswordForm">Текущий пароль</label>
                  @include('partials.password-field', [
                    'id' => 'profile-current-password',
                    'name' => 'current_password',
                    'form' => 'profilePasswordForm',
                    'placeholder' => 'Текущий пароль',
                    'autocomplete' => 'current-password',
                    'required' => true,
                  ])
                </div>
                <div class="lk-profile-form__field">
                  <label class="lk-profile-form__label" for="profile-new-password" form="profilePasswordForm">Новый пароль</label>
                  @include('partials.password-field', [
                    'id' => 'profile-new-password',
                    'name' => 'password',
                    'form' => 'profilePasswordForm',
                    'placeholder' => 'Не менее 8 символов',
                    'autocomplete' => 'new-password',
                    'required' => true,
                  ])
                </div>
                <div class="lk-profile-form__field">
                  <label class="lk-profile-form__label" for="profile-new-password-confirm" form="profilePasswordForm">Повторите новый пароль</label>
                  @include('partials.password-field', [
                    'id' => 'profile-new-password-confirm',
                    'name' => 'password_confirmation',
                    'form' => 'profilePasswordForm',
                    'placeholder' => 'Повторите пароль',
                    'autocomplete' => 'new-password',
                    'required' => true,
                  ])
                </div>
                <div class="lk-profile-form__actions">
                  <button type="submit" form="profilePasswordForm" class="btn btn--line">Сохранить пароль</button>
                </div>
              </div>

              {{-- Удаление аккаунта. Обязательный пункт для публикации приложения
                   в App Store и Google Play, на сайте — та же точка входа. --}}
              <div class="lk-profile-form__section lk-danger" id="danger">
                <h2 class="lk-profile-form__heading">Удаление аккаунта</h2>
                @if ($errors->getBag('deleteAccount')->any())
                  <div class="auth__alert auth__alert--error lk__alert">
                    @foreach ($errors->getBag('deleteAccount')->all() as $error)
                      <p>{{ $error }}</p>
                    @endforeach
                  </div>
                @endif
                <p class="lk-danger__text">
                  Профиль, переписка со студией и записи на занятия будут удалены без возможности
                  восстановления, неиспользованные занятия абонемента сгорят. Сведения об оплатах
                  сохранятся в обезличенном виде — этого требует закон.
                  <a href="{{ route('legal.account-delete') }}" target="_blank" rel="noopener">Подробнее</a>.
                </p>
                <form id="profileDeleteForm" action="{{ route('account.destroy') }}" method="post">
                  @csrf
                  @method('DELETE')
                  <div class="lk-profile-form__field">
                    <label class="lk-profile-form__label" for="profile-delete-password">Пароль</label>
                    @include('partials.password-field', [
                      'id' => 'profile-delete-password',
                      'name' => 'password',
                      'placeholder' => 'Текущий пароль',
                      'autocomplete' => 'current-password',
                      'required' => true,
                    ])
                  </div>
                  <label class="lk-danger__confirm">
                    <input type="checkbox" name="confirm" value="1" required />
                    <span>Понимаю, что данные будут удалены безвозвратно</span>
                  </label>
                  <div class="lk-profile-form__actions">
                    <button type="submit" class="btn btn--danger">Удалить аккаунт</button>
                  </div>
                </form>
              </div>
              </div>

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
            <p class="lk__lead">Групповой абонемент нельзя тратить на индивидуальное занятие и наоборот — при бронировании подходящий тип выбирается автоматически.</p>
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

          {{-- Мои бронирования --}}
          <div class="lk__panel is-hidden" data-panel="bookings">
            <h1 class="lk__title">Мои бронирования</h1>
            <p class="lk__lead">Бронирования на ближайшую неделю. Отменить или перенести бронирование без списания можно в те же сроки, что указаны в правилах на странице расписания.</p>
            @if ($errors->has('booking'))
              <div class="auth__alert auth__alert--error" style="margin-bottom: 16px">{{ $errors->first('booking') }}</div>
            @endif
            <div class="lk-list">
              @forelse($bookings as $booking)
                @php $session = $booking->classSession; @endphp
                <div class="lk-row">
                  <div class="lk-row__when">
                    <strong>{{ $session->formattedTime() }}</strong>
                    <span>{{ \App\Support\RussianDate::weekdayShortDayMonth($session->starts_at) }}</span>
                  </div>
                  <div class="lk-row__main">
                    <h3>{{ $session->direction?->title ?: $session->topic ?: $session->title }}</h3>
                    @if($session->direction && $session->topic)
                      <p class="lk-row__topic">{{ $session->topic }}</p>
                    @endif
                    <p>
                      <span class="badge badge--{{ $session->type->badgeClass() }}">{{ $session->type->shortLabel() }}</span>
                      {{ $session->trainerName() }}
                    </p>
                  </div>
                  <div class="lk-row__actions">
                    @if($booking->canBeCancelledByClient())
                      <a href="{{ route('schedule', ['reschedule' => $booking->id]) }}" class="btn btn--solid">Перенести</a>
                    @endif
                    <form action="{{ route('bookings.cancel', $booking) }}" method="post">
                      @csrf
                      <button type="submit" class="btn btn--ghost">Отменить</button>
                    </form>
                  </div>
                </div>
              @empty
                <p class="lk__empty">Пока нет активных бронирований. <a href="{{ route('schedule') }}">Посмотреть расписание</a></p>
              @endforelse
            </div>
            <a href="{{ route('schedule') }}" class="btn btn--solid" style="margin-top: 6px">Забронировать место</a>
          </div>

          {{-- История --}}
          <div class="lk__panel is-hidden" data-panel="history">
            <h1 class="lk__title">История посещений</h1>
            <p class="lk__lead">Занятия, которые вы посетили, и с какого абонемента они списаны.</p>
            <table class="lk-table lk-table--stacked">
              <thead><tr><th>Дата</th><th>Занятие</th><th>Абонемент</th></tr></thead>
              <tbody>
                @forelse($history as $h)
                  <tr>
                    <td data-label="Дата">{{ $h['date'] }}</td>
                    <td data-label="Занятие">{{ $h['title'] }}</td>
                    <td data-label="Абонемент">{{ $h['sub'] }}</td>
                  </tr>
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

            @if ($errors->has('offer'))
              <div class="auth__alert auth__alert--error lk__alert">{{ $errors->first('offer') }}</div>
            @endif
            @if ($errors->has('offer_accepted'))
              <div class="auth__alert auth__alert--error lk__alert">{{ $errors->first('offer_accepted') }}</div>
            @endif

            @if ($user->hasAcceptedOffer())
              <div class="auth__alert auth__alert--ok lk__alert">
                Оферта принята {{ $user->formattedOfferAcceptedAt() }}.
              </div>
            @endif

            <div class="oferta">
              <span class="oferta__icon">PDF</span>
              <div class="oferta__info">
                <strong>Договор публичной оферты</strong>
                <span>Просмотр в защищённом режиме, без прямого скачивания.</span>
              </div>
              @if ($offerAvailable)
                <a href="{{ route('legal.offer') }}" target="_blank" rel="noopener" class="btn btn--solid">Открыть оферту</a>
              @else
                <button type="button" class="btn btn--solid" data-soon="Договор-оферта появится здесь, как только студия загрузит документ. Загляните чуть позже.">Открыть оферту</button>
              @endif
            </div>

            @if (! $user->hasAcceptedOffer())
              <form action="{{ route('account.offer.accept') }}" method="post" class="lk-offer-accept" style="margin-top: 24px">
                @csrf
                <label class="auth__check auth__check--block">
                  <input type="checkbox" name="offer_accepted" value="1" @checked(old('offer_accepted')) required />
                  Соглашаюсь с условиями
                  @if ($offerAvailable)
                    <a href="{{ route('legal.offer') }}" class="auth__minor" target="_blank" rel="noopener">договора-оферты</a>
                  @else
                    договора-оферты
                  @endif
                  , правилами абонементов, обработкой персональных данных и информационными уведомлениями студии
                </label>
                <button type="submit" class="btn btn--solid" style="margin-top: 16px">Подтвердить согласие</button>
              </form>
            @endif
          </div>
        </div>
      </div>
    </div>
  </section>
@endsection
