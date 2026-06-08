<div class="pricing pricing--tables">
  @foreach(config('pricing') as $key => $block)
    <article class="price-table reveal {{ $key === 'individual' ? 'price-table--accent' : '' }}" style="--d:{{ $loop->index * 0.1 + 0.05 }}s">
      <h3 class="price-table__title">{{ $block['title'] }}</h3>

      @foreach($block['sections'] as $section)
        @if(!empty($section['title']))
          <p class="price-table__subtitle">{{ $section['title'] }}</p>
        @endif
        <ul class="price-table__rows">
          @foreach($section['items'] as $item)
            <li class="price-table__row @if($item['highlight'] ?? false) price-table__row--highlight @endif">
              <span class="price-table__label">{{ $item['name'] }}</span>
              <span class="price-table__dots" aria-hidden="true"></span>
              <span class="price-table__amount">{{ number_format($item['price'], 0, '', ' ') }} ₽</span>
            </li>
          @endforeach
        </ul>
      @endforeach

      <ul class="price-table__notes">
        @foreach($block['notes'] as $note)
          <li>{{ $note }}</li>
        @endforeach
      </ul>

      <a href="{{ route('login') }}" class="btn {{ $key === 'individual' ? 'btn--solid' : 'btn--line' }} btn--full">Записаться в кабинете</a>
      @auth
        @if(auth()->user()->role === \App\Enums\UserRole::Client)
          <a href="{{ route('purchase.index') }}" class="btn btn--ghost btn--full" style="margin-top: 10px">Купить абонемент</a>
        @endif
      @endauth
    </article>
  @endforeach
</div>
