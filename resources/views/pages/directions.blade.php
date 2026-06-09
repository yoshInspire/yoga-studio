@extends('layouts.site')

@section('title', 'Направления студии — Студия йоги Ирины Коленцевой')

@section('content')
  <section class="section directions directions-page" id="directions">
    <div class="container">
      <div class="section__head">
        <div>
          <p class="eyebrow reveal">Что мы практикуем</p>
          <h1 class="section__title reveal">Направления студии</h1>
        </div>
      </div>

      <p class="directions__intro reveal">
        В студии представлено 17 направлений йоги и оздоровительных практик для гостей разного уровня подготовки.
        Нажмите «Подробнее», чтобы узнать о практике и понять, что подойдёт именно вам.
      </p>

      <div class="cards">
        @foreach(config('directions.items') as $i => $card)
          <article class="card reveal" style="--d:{{ ($i % 4) * 0.08 + 0.05 }}s">
            <div class="card__img" style="background-image:url('{{ \App\Support\DirectionMedia::url($card['img']) }}')"></div>
            <div class="card__body">
              <span class="card__num">{{ $card['num'] }}</span>
              <h3 class="card__title">{{ $card['title'] }}</h3>
              <button type="button" class="card__more" data-dir="{{ $card['slug'] }}">Подробнее</button>
            </div>
          </article>
        @endforeach
      </div>

      <div class="directions__more reveal">
        <a href="{{ route('home') }}#contacts" class="btn btn--line btn--lg">Остались вопросы? Напишите нам</a>
      </div>
    </div>
  </section>

  @include('partials.direction-modals')
@endsection
