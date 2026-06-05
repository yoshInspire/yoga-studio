<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="@yield('meta_description', 'Студия йоги Ирины Коленцевой в Москве (р-н Коньково). Уютная студия, группы до 6 человек, индивидуальный подход.')" />
  <title>@yield('title', 'Студия йоги Ирины Коленцевой — Москва, Коньково')</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Nunito:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ asset('css/site.css') }}?v=15" />
  @stack('head')
</head>
<body>

  <div class="topbar">
    <div class="topbar__inner">
      <span class="topbar__item">Москва · р-н Коньково · ул. Академика Арцимовича, 13</span>
      <span class="topbar__dot">•</span>
      <span class="topbar__item">Маленькие группы до 6 человек · первое занятие пробное</span>
    </div>
  </div>

  <header class="header" id="header">
    <div class="header__inner">
      <a href="{{ route('home') }}#hero" class="logo">
        <span class="logo__mark" aria-hidden="true">◐</span>
        <span class="logo__text">ЙОГА<small>студия Ирины Коленцевой</small></span>
      </a>

      <nav class="nav" id="nav">
        <a href="{{ route('home') }}#directions" class="nav__link">Направления</a>
        <a href="{{ route('home') }}#services" class="nav__link">Услуги</a>
        <a href="{{ route('home') }}#teachers" class="nav__link">Тренеры</a>
        <a href="{{ route('home') }}#reviews" class="nav__link">Отзывы</a>
        <a href="{{ route('home') }}#about" class="nav__link">О студии</a>
        <a href="{{ route('home') }}#contacts" class="nav__link">Контакты</a>
      </nav>

      <div class="header__actions">
        <a href="{{ route('schedule') }}" class="btn btn--ghost">Расписание</a>
        @auth
          <a href="{{ route('account') }}" class="btn btn--solid">Личный кабинет</a>
        @else
          <a href="{{ route('login') }}" class="btn btn--solid">Личный кабинет</a>
        @endauth
        <button class="burger" id="burger" type="button" aria-label="Меню" aria-expanded="false">
          <span></span><span></span><span></span>
        </button>
      </div>
    </div>
  </header>

  <div class="mobile-menu" id="mobileMenu">
    <a href="{{ route('home') }}#directions" class="mobile-menu__link">Направления</a>
    <a href="{{ route('home') }}#services" class="mobile-menu__link">Услуги</a>
    <a href="{{ route('home') }}#teachers" class="mobile-menu__link">Тренеры</a>
    <a href="{{ route('home') }}#reviews" class="mobile-menu__link">Отзывы</a>
    <a href="{{ route('home') }}#about" class="mobile-menu__link">О студии</a>
    <a href="{{ route('home') }}#contacts" class="mobile-menu__link">Контакты</a>
    <div class="mobile-menu__actions">
      <a href="{{ route('schedule') }}" class="btn btn--ghost">Расписание</a>
      @auth
        <a href="{{ route('account') }}" class="btn btn--solid">Личный кабинет</a>
      @else
        <a href="{{ route('login') }}" class="btn btn--solid">Личный кабинет</a>
      @endauth
    </div>
  </div>

  <main>
    @yield('content')
  </main>

  <footer class="footer">
    <div class="container footer__inner">
      <div class="footer__brand">
        <a href="{{ route('home') }}#hero" class="logo logo--light">
          <span class="logo__mark" aria-hidden="true">◐</span>
          <span class="logo__text">ЙОГА<small>студия Ирины Коленцевой</small></span>
        </a>
        <p>Уютная студия йоги в районе Коньково.<br />Маленькие группы, бережный подход, тёплая атмосфера.</p>
      </div>
      <div class="footer__cols">
        <div class="footer__col">
          <h4>Студия</h4>
          <a href="{{ route('home') }}#directions">Направления</a>
          <a href="{{ route('home') }}#services">Услуги и цены</a>
          <a href="{{ route('home') }}#teachers">Тренеры</a>
          <a href="{{ route('home') }}#reviews">Отзывы</a>
        </div>
        <div class="footer__col">
          <h4>Гостям</h4>
          <a href="{{ route('schedule') }}">Расписание</a>
          <a href="{{ route('login') }}">Личный кабинет</a>
          <a href="https://t.me/yogAvLife" target="_blank" rel="noopener">Telegram студии</a>
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
        <span>© {{ date('Y') }} Студия йоги Ирины Коленцевой</span>
      </div>
    </div>
  </footer>

  <script src="{{ asset('js/site.js') }}?v=7"></script>
  @stack('scripts')
</body>
</html>
