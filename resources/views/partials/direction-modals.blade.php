{{-- Подробные окна направлений. Открываются кнопками с атрибутом data-dir="{slug}". --}}
<div class="dir-modal" id="dirModal" aria-hidden="true">
  <div class="dir-modal__backdrop" data-close></div>

  <div class="dir-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="dirModalTitle">
    <button type="button" class="dir-modal__close" data-close aria-label="Закрыть">&times;</button>

    <div class="dir-modal__scroll">
      @foreach(config('directions.items') as $dir)
        <article class="dir-detail" data-dir="{{ $dir['slug'] }}" hidden>
          <header class="dir-detail__head">
            <span class="dir-detail__tag">{{ $dir['tag'] }}</span>
            <h2 class="dir-detail__title" @if($loop->first) id="dirModalTitle" @endif>{{ $dir['title'] }}</h2>
            <p class="dir-detail__lead">{{ $dir['lead'] }}</p>
          </header>

          @if(!empty($dir['gallery']))
            <div class="dir-detail__gallery">
              @foreach($dir['gallery'] as $photo)
                <div class="dir-detail__photo" style="background-image:url('https://images.unsplash.com/{{ $photo }}?auto=format&fit=crop&w=900&q=80')"></div>
              @endforeach
            </div>
          @endif

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

          <div class="dir-detail__cta">
            <a href="{{ route('schedule') }}" class="btn btn--solid btn--lg">Записаться на занятие</a>
            <a href="{{ route('login') }}" class="btn btn--line btn--lg">Личный кабинет</a>
          </div>
        </article>
      @endforeach
    </div>
  </div>
</div>
