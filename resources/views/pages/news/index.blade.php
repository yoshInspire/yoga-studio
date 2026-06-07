@extends('layouts.site')

@section('title', 'Новости студии — Студия йоги Ирины Коленцевой')

@section('content')
  <section class="section directions" id="news">
    <div class="container">
      <div class="section__head">
        <div>
          <p class="eyebrow reveal">Жизнь студии</p>
          <h1 class="section__title reveal">Новости и события</h1>
        </div>
      </div>

      <p class="directions__intro reveal">
        Анонсы занятий, мероприятия, полезные заметки и всё, чем живёт студия.
      </p>

      @if ($news->isEmpty())
        <p class="lk__empty reveal">Пока новостей нет — заглядывайте позже.</p>
      @else
        <div class="cards">
          @foreach($news as $i => $item)
            <article class="card reveal" style="--d:{{ ($i % 3) * 0.08 + 0.05 }}s">
              <div class="card__img" @if($item->imageUrl()) style="background-image:url('{{ $item->imageUrl() }}')" @endif></div>
              <div class="card__body">
                <span class="card__num">{{ $item->formattedDate() }}</span>
                <h3 class="card__title">{{ $item->title }}</h3>
                <p class="card__text">{{ $item->readableExcerpt() }}</p>
                <a href="{{ route('news.show', $item) }}" class="card__more">Читать</a>
              </div>
            </article>
          @endforeach
        </div>

        <div class="news__pagination reveal" style="margin-top: 32px">
          {{ $news->links('vendor.pagination.studio') }}
        </div>
      @endif
    </div>
  </section>
@endsection
