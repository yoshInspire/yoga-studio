{{-- Подробные окна направлений. Открываются кнопками с атрибутом data-dir="{slug}". --}}
<div class="dir-modal" id="dirModal" aria-hidden="true">
  <div class="dir-modal__backdrop" data-close></div>

  <div class="dir-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="dirModalTitle">
    <button type="button" class="dir-modal__close" data-close aria-label="Закрыть">&times;</button>

    <div class="dir-modal__scroll">
      @foreach(config('directions.items') as $dir)
        @php($slides = array_merge([$dir['img']], $dir['gallery'] ?? []))
        <article class="dir-detail" data-dir="{{ $dir['slug'] }}" hidden>
          <div class="dir-detail__hero">
            <div class="dir-detail__slides">
              @foreach($slides as $k => $photo)
                <div class="dir-detail__slide @if($k === 0) is-active @endif"
                     style="background-image:url('https://images.unsplash.com/{{ $photo }}?auto=format&fit=crop&w=1200&q=80')"></div>
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
              <span class="dir-detail__tag">{{ $dir['tag'] }}</span>
              <h2 class="dir-detail__title" @if($loop->first) id="dirModalTitle" @endif>{{ $dir['title'] }}</h2>
              <p class="dir-detail__lead">{{ $dir['lead'] }}</p>
            </header>

            <div class="dir-detail__body">
              @foreach($dir['body'] as $para)
                <p>{{ $para }}</p>
              @endforeach
            </div>

            @if(!empty($dir['benefits']))
              <div class="dir-detail__benefits">
                <h3>Что это даёт</h3>
                <ul>
                  @foreach($dir['benefits'] as $benefit)
                    <li>{{ $benefit }}</li>
                  @endforeach
                </ul>
              </div>
            @endif
          </div>
        </article>
      @endforeach
    </div>
  </div>
</div>
