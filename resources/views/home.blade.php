@extends('layouts.site')

@section('title', 'Студия йоги Ирины Коленцевой — Москва, Коньково')
@section('meta_description', 'Студия йоги Ирины Коленцевой в Коньково (Москва): хатха, виньяса, йогатерапия, группы до 6 человек. Расписание, абонементы, запись онлайн.')

@section('content')
  <section class="hero" id="hero">
    <div class="hero__content">
      <p class="hero__eyebrow reveal">Студия йоги, Москва, Коньково</p>
      <h1 class="hero__title">
        <span class="reveal hero__title--bold" style="--d:.05s">Каждому <span class="hero__dash" aria-hidden="true"></span> своё внимание,</span>
        <span class="reveal hero__title--bold" style="--d:.15s">каждому <span class="hero__dash" aria-hidden="true"></span> своё дыхание</span>
      </h1>
      <p class="hero__lead reveal" style="--d:.3s">
        Студия йоги Ирины Коленцевой. Камерный формат до 6 человек, деликатная индивидуальная работа и атмосфера, в которой внимание становится основой практики.
      </p>
      <div class="hero__cta reveal" style="--d:.45s">
        <a href="{{ route('login') }}" class="btn btn--solid btn--lg">Личный кабинет</a>
        <a href="{{ route('schedule') }}" class="btn btn--line btn--lg">Расписание</a>
      </div>
    </div>
    <a href="#directions" class="hero__scroll" aria-label="Вниз"><span></span></a>
  </section>

  <div class="marquee" aria-hidden="true">
    <div class="marquee__track">
      <span>Хатха-йога</span><span>•</span><span>Йогатерапия</span><span>•</span><span>Виньяса-флоу</span><span>•</span>
      <span>Инь-йога</span><span>•</span><span>Аэройога</span><span>•</span><span>Пилатес</span><span>•</span>
      <span>Йога-нидра</span><span>•</span><span>Медитация</span><span>•</span><span>Stretching йога</span><span>•</span><span>МФР</span><span>•</span>
      <span>Хатха-йога</span><span>•</span><span>Йогатерапия</span><span>•</span><span>Виньяса-флоу</span><span>•</span>
      <span>Инь-йога</span><span>•</span><span>Аэройога</span><span>•</span><span>Пилатес</span><span>•</span>
      <span>Йога-нидра</span><span>•</span><span>Медитация</span><span>•</span><span>Stretching йога</span><span>•</span><span>МФР</span><span>•</span>
    </div>
  </div>

  <section class="section directions" id="directions">
    <div class="container">
      <div class="section__head">
        <div>
          <p class="eyebrow reveal">Что мы практикуем</p>
          <h2 class="section__title reveal">Направления студии</h2>
        </div>
      </div>

      <p class="directions__intro reveal">
        В студии представлено {{ $directions->count() }} направлений йоги и оздоровительных практик для гостей разного уровня подготовки.
      </p>

      <div class="cards">
        @foreach($directions->take(8) as $i => $card)
          <article class="card reveal" style="--d:{{ $i * 0.08 + 0.05 }}s">
            <div class="card__img" style="background-image:url('{{ $card->coverUrl() }}')"></div>
            <div class="card__body">
              <span class="card__num">{{ $card->num }}</span>
              <h3 class="card__title">{{ $card->title }}</h3>
              <a href="{{ route('directions.show', $card) }}" class="card__more">Подробнее</a>
            </div>
          </article>
        @endforeach
      </div>

      <div class="directions__more reveal">
        <a href="{{ route('directions') }}" class="btn btn--solid btn--lg">Посмотреть все направления</a>
      </div>
    </div>
  </section>

  <section class="section about" id="about">
    <div class="container about__grid">
      <div class="about__media reveal">
        @php($aboutPhoto = config('studio-photos.about'))
        <div class="about__img" style="background-image:url('{{ asset($aboutPhoto['src']) }}')" role="img" aria-label="{{ $aboutPhoto['alt'] }}"></div>
      </div>
      <div class="about__text">
        <p class="eyebrow reveal">О студии</p>
        <h2 class="section__title reveal">Уютное пространство, где заботятся о каждом</h2>
        <p class="about__lead reveal">
          Камерная студия в районе Коньково: тёплая атмосфера, чистота, новый инвентарь
          и всё необходимое для комфортной практики.
        </p>
        <ul class="features">
          <li class="feature reveal" style="--d:.05s">
            <span class="feature__icon">✦</span>
            <div>
              <h4>Группы до 6 человек</h4>
              <p>Почти индивидуальный формат: тренер видит каждого и корректирует практику.</p>
            </div>
          </li>
          <li class="feature reveal" style="--d:.15s">
            <span class="feature__icon">✦</span>
            <div>
              <h4>Индивидуальный подход</h4>
              <p>Нагрузка подбирается под ваше тело, цели и состояние здоровья.</p>
            </div>
          </li>
          <li class="feature reveal" style="--d:.25s">
            <span class="feature__icon">✦</span>
            <div>
              <h4>Запись в личном кабинете</h4>
              <p>Расписание, выбор занятий и контроль абонемента — на сайте после входа.</p>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </section>

  <section class="section studio-gallery" aria-label="Фотографии студии">
    <div class="container">
      <div class="section__head">
        <div>
          <p class="eyebrow reveal">Наше пространство</p>
          <h2 class="section__title reveal">Студия, в которую хочется приходить</h2>
        </div>
        <p class="section__desc reveal">
          Светлые залы, уютная зона ожидания и всё необходимое для комфортной практики.
        </p>
      </div>
      <div class="studio-gallery__grid">
        @foreach(config('studio-photos.gallery') as $i => $photo)
          <figure class="studio-gallery__item {{ $photo['layout'] }} reveal" style="--d:{{ $i * 0.08 + 0.05 }}s">
            <img src="{{ asset($photo['src']) }}" alt="{{ $photo['alt'] }}" loading="lazy" decoding="async" />
          </figure>
        @endforeach
      </div>
    </div>
  </section>

  @if($trainers->isNotEmpty())
  <section class="section teachers" id="teachers">
    <div class="container">
      <div class="section__head">
        <div>
          <p class="eyebrow reveal">Кто ведёт занятия</p>
          <h2 class="section__title reveal">Тренеры</h2>
        </div>
        <p class="section__desc reveal">
          Внимательные преподаватели с подходом к гостю любого уровня.
        </p>
      </div>
        <div @class([
          'teachers__grid',
          'teachers__grid--two' => $trainers->count() <= 2,
        ])>
          @foreach($trainers as $i => $trainer)
            <article class="teacher reveal" style="--d:{{ $i * 0.1 + 0.05 }}s">
              <div
                @class(['teacher__img', 'teacher__img--placeholder' => ! $trainer->trainerPhotoUrl()])
                @if($trainer->trainerPhotoUrl())
                  style="background-image:url('{{ $trainer->trainerPhotoUrl() }}')"
                @endif
              >
                @unless($trainer->trainerPhotoUrl())
                  <span aria-hidden="true">{{ $trainer->initials() }}</span>
                @endunless
              </div>
              <h3 class="teacher__name">{{ $trainer->trainerDisplayName() }}</h3>
              @if($trainer->trainer_title)
                <p class="teacher__role">{{ $trainer->trainer_title }}</p>
              @endif
              @if($trainer->trainer_bio)
                <p class="teacher__bio">{{ $trainer->trainer_bio }}</p>
              @endif
            </article>
          @endforeach
        </div>
    </div>
  </section>
  @endif

  <section class="section services" id="services">
    <div class="container">
      <div class="section__head">
        <div>
          <p class="eyebrow reveal">Форматы занятий</p>
          <h2 class="section__title reveal">Услуги и цены</h2>
        </div>
        <p class="section__desc reveal">Актуальные цены студии на групповые и индивидуальные форматы. Запись на&nbsp;занятие осуществляется по&nbsp;100% предоплате.</p>
      </div>
      @include('partials.pricing')
    </div>
  </section>

  <section class="section reviews" id="reviews">
    <div class="container">
      <div class="section__head">
        <div>
          <p class="eyebrow reveal">Что говорят гости</p>
          <h2 class="section__title reveal">Отзывы</h2>
        </div>
      </div>
      @include('partials.yandex-reviews-widget')
    </div>
  </section>

  <section class="cta">
    <div class="cta__bg"></div>
    <div class="container cta__inner">
      <p class="eyebrow eyebrow--light reveal">Впервые на йоге?</p>
      <h2 class="cta__title reveal">Начните с пробного занятия</h2>
      <p class="cta__text reveal">
        Пробное групповое занятие — {{ number_format($trialPrice, 0, '', ' ') }} ₽. Подберём направление под ваши цели.
      </p>
      <a href="{{ route('login') }}" class="btn btn--solid btn--lg reveal">Войти в личный кабинет</a>
    </div>
  </section>

  <section class="section contacts" id="contacts">
    <div class="container contacts__grid">
      <div class="contacts__info">
        <p class="eyebrow reveal">Контакты</p>
        <h2 class="section__title reveal">Будем рады видеть вас</h2>
        <ul class="contacts__list reveal">
          <li><span>Адрес</span> Москва, ул. Академика Арцимовича, 13 (вход со двора) · р-н Коньково</li>
          <li><span>Метро</span> Коньково · Беляево</li>
          <li><span>Телефон</span> <a href="tel:+79647834353">+7 (964) 783-43-53</a></li>
          <li><span>Telegram</span> <a href="https://t.me/yogAvLife" target="_blank" rel="noopener">@yogAvLife</a></li>
          <li><span>Часы работы</span> ежедневно · 07:00 — 22:00</li>
        </ul>
        <div class="contacts__socials reveal">
          <a href="https://t.me/irinikayoga" target="_blank" rel="noopener">Telegram</a>
          <a href="tel:+79647834353">Позвонить</a>
        </div>
      </div>
      <form class="contacts__form reveal" action="{{ route('lead.store') }}" method="post" id="leadForm">
        @csrf
        <h3 class="form__title">Оставить заявку</h3>
        <p class="form__sub">Оставьте контакты — мы поможем подобрать занятие.</p>
        @if (session('lead_status'))
          <div class="auth__alert auth__alert--ok" style="margin-bottom: 14px">{{ session('lead_status') }}</div>
        @endif
        @if (session('lead_error'))
          <div class="auth__alert auth__alert--error" style="margin-bottom: 14px">{{ session('lead_error') }}</div>
        @endif
        @if ($errors->any())
          <div class="auth__alert auth__alert--error" style="margin-bottom: 14px">{{ $errors->first() }}</div>
        @endif
        <div class="form__row"><input type="text" name="name" value="{{ old('name') }}" placeholder="Ваше имя" required /></div>
        <div class="form__row"><input type="tel" name="phone" value="{{ old('phone') }}" placeholder="+7 (___) ___-__-__" autocomplete="tel" inputmode="tel" data-phone-mask required /></div>
        <div class="form__row"><input type="text" name="message" value="{{ old('message') }}" placeholder="Комментарий (необязательно)" /></div>
        {{-- honeypot: скрыто от людей, ловит ботов --}}
        <div style="position:absolute;left:-9999px" aria-hidden="true">
          <input type="text" name="company" tabindex="-1" autocomplete="off" />
        </div>
        <button class="btn btn--solid btn--full" type="submit">Отправить заявку</button>
        <p class="form__note">Нажимая кнопку, вы соглашаетесь с политикой обработки персональных данных.</p>
      </form>
    </div>
  </section>

  @include('partials.direction-modals')
@endsection

@push('head')
  <script type="application/ld+json">{!! json_encode(\App\Support\Seo::localBusinessJsonLd(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush
