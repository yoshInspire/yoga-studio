@php($slides = $direction->slidePaths())
<article class="dir-detail dir-detail--page">
  <div class="dir-detail__hero">
    <div class="dir-detail__slides">
      @foreach($slides as $k => $photo)
        <div class="dir-detail__slide @if($k === 0) is-active @endif"
             style="background-image:url('{{ \App\Support\DirectionMedia::url($photo, 1200) }}')"
             role="img"
             aria-label="{{ $direction->title }} — фото {{ $k + 1 }}"></div>
      @endforeach
    </div>
    <div class="dir-detail__hero-overlay"></div>
    @include('partials.logo', ['theme' => 'light', 'class' => 'logo__img--dir-detail'])
    @if(count($slides) > 1)
      <button type="button" class="dir-detail__nav dir-detail__nav--prev" data-nav="prev" aria-label="Предыдущее фото">&lsaquo;</button>
      <button type="button" class="dir-detail__nav dir-detail__nav--next" data-nav="next" aria-label="Следующее фото">&rsaquo;</button>
    @endif
  </div>

  <div class="dir-detail__content">
    <header class="dir-detail__head">
      @if(filled($direction->tag))
        <span class="dir-detail__tag">{{ $direction->tag }}</span>
      @endif
      <h1 class="dir-detail__title">{{ $direction->title }}</h1>
      <p class="dir-detail__lead">{{ $direction->lead }}</p>
    </header>

    @if(!empty($direction->body))
      <div class="dir-detail__body">
        @foreach($direction->body as $para)
          <p>{{ $para }}</p>
        @endforeach
      </div>
    @endif

    @if(!empty($direction->benefits))
      <div class="dir-detail__benefits">
        <h2>Что это даёт</h2>
        <ul>
          @foreach($direction->benefits as $benefit)
            <li>{{ $benefit }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <div class="dir-detail__actions">
      <a href="{{ route('schedule') }}" class="btn btn--solid btn--lg">Расписание</a>
      <a href="{{ route('login') }}" class="btn btn--line btn--lg">Записаться</a>
    </div>
  </div>
</article>
