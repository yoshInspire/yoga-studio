<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  @include('partials.seo-head')
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>@yield('title', config('seo.default_title'))</title>
  <link rel="icon" href="{{ asset('images/favicon.ico') }}" sizes="any" />
  <link rel="icon" type="image/png" href="{{ asset('images/favico.png') }}" />
  <link rel="apple-touch-icon" href="{{ asset('images/favico.png') }}" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Nunito:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ asset('css/site.css') }}?v=51" />
  @stack('head')
</head>
<body>

  <header class="header" id="header">
    <div class="header__inner">
      <a href="{{ route('home') }}#hero" class="logo logo--header">
        @include('partials.logo', ['variant' => 'header'])
      </a>

      <nav class="nav" id="nav">
        <a href="{{ route('directions') }}" class="nav__link">Направления</a>
        <a href="{{ route('home') }}#services" class="nav__link">Услуги</a>
        <a href="{{ route('home') }}#teachers" class="nav__link">Тренеры</a>
        <a href="{{ route('news.index') }}" class="nav__link">Новости</a>
        <a href="{{ route('schedule') }}" class="nav__link">Расписание</a>
        <a href="{{ route('home') }}#about" class="nav__link">О студии</a>
        <a href="{{ route('home') }}#contacts" class="nav__link">Контакты</a>
      </nav>

      <div class="header__actions">
        @include('partials.header-icons')
        <button class="burger" id="burger" type="button" aria-label="Меню" aria-expanded="false">
          <span></span><span></span><span></span>
        </button>
      </div>
    </div>
  </header>

  <div class="mobile-menu" id="mobileMenu">
    <a href="{{ route('directions') }}" class="mobile-menu__link">Направления</a>
    <a href="{{ route('home') }}#services" class="mobile-menu__link">Услуги</a>
    <a href="{{ route('home') }}#teachers" class="mobile-menu__link">Тренеры</a>
    <a href="{{ route('news.index') }}" class="mobile-menu__link">Новости</a>
    <a href="{{ route('schedule') }}" class="mobile-menu__link">Расписание</a>
    <a href="{{ route('home') }}#about" class="mobile-menu__link">О студии</a>
    <a href="{{ route('home') }}#contacts" class="mobile-menu__link">Контакты</a>
    <div class="mobile-menu__actions mobile-menu__actions--icons">
      @include('partials.header-icons')
    </div>
  </div>

  <main>
    @yield('content')
  </main>

  <footer class="footer">
    <div class="container footer__inner">
      <div class="footer__brand">
        <a href="{{ route('home') }}#hero" class="logo">
          @include('partials.logo', ['theme' => 'light', 'class' => 'logo__img--footer'])
        </a>
        <p>Уютная студия йоги в районе Коньково.<br />Маленькие группы, бережный подход, тёплая атмосфера.</p>
      </div>
      <div class="footer__cols">
        <div class="footer__col">
          <h4>Студия</h4>
          <a href="{{ route('directions') }}">Направления</a>
          <a href="{{ route('home') }}#services">Услуги и цены</a>
          <a href="{{ route('home') }}#teachers">Тренеры</a>
          <a href="{{ route('news.index') }}">Новости</a>
          <a href="{{ route('schedule') }}">Расписание</a>
        </div>
        <div class="footer__col">
          <h4>Гостям</h4>
          <a href="{{ route('schedule') }}">Расписание</a>
          <a href="{{ route('directions') }}">Направления</a>
          <a href="{{ route('login') }}">Личный кабинет</a>
          <a href="https://t.me/yogAvLife" target="_blank" rel="noopener">Telegram студии</a>
          <a href="{{ route('legal.offer') }}">Договор-оферта</a>
          <a href="{{ route('legal.privacy') }}">Персональные данные</a>
        </div>
        <div class="footer__col">
          <h4>Контакты</h4>
          <a href="{{ route('home') }}#contacts">ул. Ак. Арцимовича, 13</a>
          <a href="tel:+79647834353">+7 (964) 783-43-53</a>
          <a href="https://t.me/yogAvLife" target="_blank" rel="noopener">@yogAvLife</a>
          <a href="{{ route('home') }}#contacts">Ежедневно 07:00–22:00</a>
        </div>
      </div>
    </div>
    <div class="footer__bottom">
      <div class="container footer__bottom-inner">
        <div class="footer__copy">
          <span>© {{ date('Y') }} Студия йоги Ирины Коленцевой</span>
          <span class="footer__credit">Made by <a href="https://t.me/yoshinspire" target="_blank" rel="noopener">Alexey</a></span>
        </div>
      </div>
    </div>
  </footer>

  {{-- Универсальная модалка «функция в разработке» --}}
  <div class="soon-modal" id="soonModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="soonModalTitle">
    <div class="soon-modal__overlay" data-soon-close></div>
    <div class="soon-modal__box" role="document">
      <button type="button" class="soon-modal__close" data-soon-close aria-label="Закрыть">&times;</button>
      <span class="soon-modal__icon" aria-hidden="true">✦</span>
      <h3 class="soon-modal__title" id="soonModalTitle">Скоро будет доступно</h3>
      <p class="soon-modal__text" id="soonModalText">Эта возможность ещё в разработке — мы добавим её в ближайшее время.</p>
      <button type="button" class="btn btn--solid" data-soon-close>Понятно</button>
    </div>
  </div>

  <script src="{{ asset('js/phone-mask.js') }}?v=1"></script>
  <script src="{{ asset('js/site.js') }}?v=23"></script>
  @stack('scripts')
</body>
</html>
