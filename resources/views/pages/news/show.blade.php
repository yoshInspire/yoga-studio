@extends('layouts.site')

@section('title', $item->title.' — Новости студии йоги Ирины Коленцевой')
@section('meta_description', $item->readableExcerpt())

@section('content')
  <article class="section news-article">
    <div class="container container--narrow">
      <p class="eyebrow reveal"><a href="{{ route('news.index') }}" class="news-article__back">← Все новости</a></p>
      <h1 class="section__title reveal">{{ $item->title }}</h1>
      <p class="news-article__date reveal">{{ $item->formattedDate() }}</p>

      @if ($item->imageUrl())
        <div class="news-article__cover reveal" style="background-image:url('{{ $item->imageUrl() }}')"></div>
      @endif

      <div class="news-article__body reveal">
        {!! nl2br(e($item->body)) !!}
      </div>

      @include('partials.news-reactions', [
        'news' => $item,
        'summary' => $reactionSummary,
      ])
    </div>

    @if ($more->isNotEmpty())
      <div class="container" style="margin-top: 48px">
        <h2 class="section__title reveal" style="font-size: 1.6rem">Ещё новости</h2>
        <div class="cards">
          @foreach($more as $i => $other)
            <article class="card reveal" style="--d:{{ $i * 0.08 + 0.05 }}s">
              <div class="card__img" @if($other->imageUrl()) style="background-image:url('{{ $other->imageUrl() }}')" @endif></div>
              <div class="card__body">
                <span class="card__num">{{ $other->formattedDate() }}</span>
                <h3 class="card__title">{{ $other->title }}</h3>
                <p class="card__text">{{ $other->readableExcerpt() }}</p>
                <a href="{{ route('news.show', $other) }}" class="card__more">Читать</a>
              </div>
            </article>
          @endforeach
        </div>
      </div>
    @endif
  </article>
@endsection

@push('scripts')
  <script src="{{ asset('js/news-reactions.js') }}?v=1"></script>
@endpush
