@extends('layouts.site')

@section('title', 'Вход и регистрация — Студия йоги Ирины Коленцевой')

@php
  $months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
  $activeTab = $activeTab ?? session('auth_tab', 'login');
@endphp

@section('content')
  <section class="section auth">
    <div class="container">
      <div class="auth__wrap reveal">
        <aside class="auth__aside">
          <div class="auth__aside-overlay"></div>
          <div class="auth__aside-content">
            <span class="logo__text auth__brand">ЙОГА<small>студия Ирины Коленцевой</small></span>
            <h2 class="auth__aside-title">Личный кабинет</h2>
            <p class="auth__aside-text">
              Расписание доступно всем гостям. Войдите или зарегистрируйтесь, чтобы
              записываться на занятия, видеть остаток по абонементам и историю посещений.
            </p>
            <a href="{{ route('schedule') }}" class="auth__aside-link">Смотреть расписание</a>
          </div>
        </aside>

        <div class="auth__panel">
          @if (session('status'))
            <div class="auth__alert auth__alert--ok">{{ session('status') }}</div>
          @endif

          <div class="auth__tabs" role="tablist">
            <button type="button" class="auth__tab {{ $activeTab === 'login' ? 'is-active' : '' }}" data-tab="login" role="tab" aria-selected="{{ $activeTab === 'login' ? 'true' : 'false' }}">Вход</button>
            <button type="button" class="auth__tab {{ $activeTab === 'register' ? 'is-active' : '' }}" data-tab="register" role="tab" aria-selected="{{ $activeTab === 'register' ? 'true' : 'false' }}">Регистрация</button>
          </div>

          {{-- Вход --}}
          <form class="auth__form {{ $activeTab === 'register' ? 'is-hidden' : '' }}" data-form="login" action="{{ route('login.store') }}" method="post">
            @csrf
            <p class="form__sub">Введите email или телефон и пароль.</p>

            @if ($errors->getBag('login')->any())
              <div class="auth__alert auth__alert--error">
                @foreach ($errors->getBag('login')->all() as $error)
                  <p>{{ $error }}</p>
                @endforeach
              </div>
            @endif

            <div class="form__row">
              <label class="auth__label" for="login-id">Email или телефон</label>
              <input type="text" id="login-id" name="login" value="{{ old('login') }}" autocomplete="username" inputmode="text" data-phone-mask="login" required />
            </div>
            <div class="form__row">
              <label class="auth__label" for="login-pass">Пароль</label>
              @include('partials.password-field', [
                'id' => 'login-pass',
                'name' => 'password',
                'placeholder' => '',
                'autocomplete' => 'current-password',
                'required' => true,
              ])
            </div>
            <div class="auth__row-between">
              <label class="auth__check"><input type="checkbox" name="remember" value="1" @checked(old('remember')) /> Запомнить меня</label>
              <a href="#" class="auth__minor" data-soon="Восстановление пароля скоро появится. Пока, если забыли пароль, напишите или позвоните в студию — администратор поможет восстановить доступ.">Забыли пароль?</a>
            </div>
            <button type="submit" class="btn btn--solid btn--full btn--lg">Войти</button>
            <p class="auth__switch">Нет аккаунта? <button type="button" class="auth__link" data-goto="register">Зарегистрироваться</button></p>
          </form>

          {{-- Регистрация --}}
          <form class="auth__form {{ $activeTab === 'register' ? '' : 'is-hidden' }}" data-form="register" action="{{ route('register') }}" method="post">
            @csrf

            @if ($errors->getBag('register')->any())
              <div class="auth__alert auth__alert--error">
                @foreach ($errors->getBag('register')->all() as $error)
                  <p>{{ $error }}</p>
                @endforeach
              </div>
            @endif

            <div class="auth__row2">
              <div class="form__row">
                <label class="auth__label" for="reg-name">Имя</label>
                <input type="text" id="reg-name" name="first_name" value="{{ old('first_name') }}" placeholder="Имя" autocomplete="given-name" required />
              </div>
              <div class="form__row">
                <label class="auth__label" for="reg-surname">Фамилия</label>
                <input type="text" id="reg-surname" name="last_name" value="{{ old('last_name') }}" placeholder="Фамилия" autocomplete="family-name" required />
              </div>
            </div>

            <label class="auth__check auth__check--block" style="margin-bottom: 10px">
              <input type="checkbox" id="patronymic-toggle" @checked(old('patronymic')) /> Указать отчество
            </label>
            <div class="form__row auth__patronymic {{ old('patronymic') ? '' : 'is-hidden' }}" id="patronymic-field">
              <label class="auth__label" for="reg-patronymic">Отчество</label>
              <input type="text" id="reg-patronymic" name="patronymic" value="{{ old('patronymic') }}" placeholder="Отчество" autocomplete="additional-name" />
            </div>

            <label class="auth__label">Дата рождения</label>
            <div class="auth__row3 auth__birth">
              <div class="form__row">
                <div class="auth__select-wrap">
                  <select name="birth_day" class="auth__select" aria-label="День" required>
                    <option value="">День</option>
                    @for($d = 1; $d <= 31; $d++)
                      <option value="{{ $d }}" @selected((int) old('birth_day') === $d)>{{ $d }}</option>
                    @endfor
                  </select>
                </div>
              </div>
              <div class="form__row">
                <div class="auth__select-wrap">
                  <select name="birth_month" class="auth__select auth__select--month" aria-label="Месяц" required>
                    <option value="">Месяц</option>
                    @foreach($months as $idx => $m)
                      <option value="{{ $idx + 1 }}" @selected((int) old('birth_month') === $idx + 1)>{{ $m }}</option>
                    @endforeach
                  </select>
                </div>
              </div>
              <div class="form__row">
                <input type="number" name="birth_year" class="auth__input-year" value="{{ old('birth_year') }}" placeholder="Год" min="1920" max="2026" aria-label="Год рождения" />
              </div>
            </div>

            <div class="form__row">
              <label class="auth__label" for="reg-phone">Телефон</label>
              <input type="tel" id="reg-phone" name="phone" value="{{ old('phone') }}" placeholder="+7 (___) ___-__-__" autocomplete="tel" inputmode="tel" data-phone-mask required />
            </div>
            <div class="form__row">
              <label class="auth__label" for="reg-email">Email <span class="auth__optional">(необязательно)</span></label>
              <input type="email" id="reg-email" name="email" value="{{ old('email') }}" placeholder="email@example.com" autocomplete="email" />
            </div>
            <div class="form__row">
              <label class="auth__label" for="reg-pass">Пароль</label>
              @include('partials.password-field', [
                'id' => 'reg-pass',
                'name' => 'password',
                'placeholder' => 'Не менее 8 символов',
                'autocomplete' => 'new-password',
                'required' => true,
              ])
            </div>
            <div class="form__row">
              <label class="auth__label" for="reg-pass-confirm">Повторите пароль</label>
              @include('partials.password-field', [
                'id' => 'reg-pass-confirm',
                'name' => 'password_confirmation',
                'placeholder' => 'Повторите пароль',
                'autocomplete' => 'new-password',
                'required' => true,
              ])
            </div>
            <label class="auth__check auth__check--block">
              <input type="checkbox" name="offer_accepted" value="1" @checked(old('offer_accepted')) required /> Соглашаюсь с условиями <a href="#" class="auth__minor" data-soon="Полный текст договора-оферты будет доступен в личном кабинете после входа, в разделе «Договор-оферта».">договора-оферты</a>
            </label>
            <button type="submit" class="btn btn--solid btn--full btn--lg">Зарегистрироваться</button>
            <p class="auth__switch">Уже есть аккаунт? <button type="button" class="auth__link" data-goto="login">Войти</button></p>
          </form>
        </div>
      </div>
    </div>
  </section>
@endsection
