@extends('layouts.site')

@section('title', 'Вход и регистрация — Студия йоги Ирины Коленцевой')

@php
  $months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
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
            <a href="{{ route('schedule') }}" class="auth__aside-link">Смотреть расписание →</a>
          </div>
        </aside>

        <div class="auth__panel">
          <div class="auth__tabs" role="tablist">
            <button type="button" class="auth__tab is-active" data-tab="login" role="tab" aria-selected="true">Вход</button>
            <button type="button" class="auth__tab" data-tab="register" role="tab" aria-selected="false">Регистрация</button>
          </div>

          {{-- Вход --}}
          <form class="auth__form" data-form="login" action="{{ route('account') }}" method="get">
            <p class="form__sub">Введите телефон и пароль, чтобы войти.</p>
            <div class="form__row">
              <label class="auth__label" for="login-phone">Телефон</label>
              <input type="tel" id="login-phone" name="phone" placeholder="+7 (___) ___-__-__" autocomplete="tel" />
            </div>
            <div class="form__row">
              <label class="auth__label" for="login-pass">Пароль</label>
              <input type="password" id="login-pass" name="password" placeholder="Ваш пароль" autocomplete="current-password" />
            </div>
            <div class="auth__row-between">
              <label class="auth__check"><input type="checkbox" /> Запомнить меня</label>
              <a href="#" class="auth__minor">Забыли пароль?</a>
            </div>
            <button type="submit" class="btn btn--solid btn--full btn--lg">Войти</button>
            <p class="auth__switch">Нет аккаунта? <button type="button" class="auth__link" data-goto="register">Зарегистрироваться</button></p>
          </form>

          {{-- Регистрация --}}
          <form class="auth__form is-hidden" data-form="register" action="{{ route('account') }}" method="get">
            <p class="form__sub">Заполните данные — первое занятие пробное.</p>
            <div class="auth__row2">
              <div class="form__row">
                <label class="auth__label" for="reg-name">Имя</label>
                <input type="text" id="reg-name" name="first_name" placeholder="Имя" autocomplete="given-name" />
              </div>
              <div class="form__row">
                <label class="auth__label" for="reg-surname">Фамилия</label>
                <input type="text" id="reg-surname" name="last_name" placeholder="Фамилия" autocomplete="family-name" />
              </div>
            </div>

            <label class="auth__label">Дата рождения</label>
            <div class="auth__row3">
              <div class="form__row">
                <select name="bday" aria-label="День">
                  <option value="">День</option>
                  @for($d = 1; $d <= 31; $d++)<option>{{ $d }}</option>@endfor
                </select>
              </div>
              <div class="form__row">
                <select name="bmonth" aria-label="Месяц">
                  <option value="">Месяц</option>
                  @foreach($months as $idx => $m)<option value="{{ $idx + 1 }}">{{ $m }}</option>@endforeach
                </select>
              </div>
              <div class="form__row">
                <input type="number" name="byear" placeholder="Год" min="1920" max="2020" />
              </div>
            </div>
            <p class="auth__hint">Год рождения указывать не обязательно — достаточно дня и месяца.</p>

            <div class="form__row">
              <label class="auth__label" for="reg-phone">Телефон</label>
              <input type="tel" id="reg-phone" name="phone" placeholder="+7 (___) ___-__-__" autocomplete="tel" />
            </div>
            <div class="form__row">
              <label class="auth__label" for="reg-pass">Пароль</label>
              <input type="password" id="reg-pass" name="password" placeholder="Придумайте пароль" autocomplete="new-password" />
            </div>
            <label class="auth__check auth__check--block">
              <input type="checkbox" /> Соглашаюсь с условиями <a href="#" class="auth__minor">договора-оферты</a>
            </label>
            <button type="submit" class="btn btn--solid btn--full btn--lg">Зарегистрироваться</button>
            <p class="auth__switch">Уже есть аккаунт? <button type="button" class="auth__link" data-goto="login">Войти</button></p>
          </form>

          <p class="auth__demo">Демо-версия интерфейса: вход и регистрация пока без проверки данных — кнопки открывают предпросмотр личного кабинета.</p>
        </div>
      </div>
    </div>
  </section>
@endsection
