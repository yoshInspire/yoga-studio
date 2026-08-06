<div class="faq reveal">
  <h2 class="faq__title">Правила бронирования</h2>
  <div class="faq__list" id="faqList">
    @foreach(\App\Support\StudioRules::items() as $item)
      <div class="faq__item">
        <button type="button" class="faq__q">{{ $item['q'] }}<span class="faq__icon">+</span></button>
        <div class="faq__a">
          @foreach($item['a'] as $paragraph)
            <p>{!! $paragraph !!}</p>
          @endforeach
        </div>
      </div>
    @endforeach
  </div>
</div>
