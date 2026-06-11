@extends('layouts.site')

@section('title', 'Купить абонемент — Студия йоги Ирины Коленцевой')

@section('content')
  <section class="section lk">
    <div class="container">
      <div class="lk__content reveal" style="max-width: 920px; margin: 0 auto;">
        <h1 class="lk__title">Купить абонемент</h1>
        <p class="lk__lead">Выберите тариф и дату начала. После успешной оплаты абонемент появится в личном кабинете — можно сразу записываться на занятия.</p>

        @if ($errors->has('purchase'))
          <div class="auth__alert auth__alert--error" style="margin-bottom: 18px">{{ $errors->first('purchase') }}</div>
        @endif

        @foreach($catalog as $category => $products)
          <div class="purchase-group">
            <h2 class="purchase-group__title">{{ \App\Support\PurchaseCatalog::categoryLabel($category) }}</h2>
            <div class="purchase-grid">
              @foreach($products as $product)
                <form action="{{ route('purchase.store') }}" method="post" class="purchase-card">
                  @csrf
                  <input type="hidden" name="product_key" value="{{ $product['key'] }}">
                  <div class="purchase-card__head">
                    <span class="badge badge--{{ $product['type']->badgeClass() }}">{{ $product['type']->shortLabel() }}</span>
                    <h3 class="purchase-card__name">{{ $product['name'] }}</h3>
                  </div>
                  <p class="purchase-card__meta">
                    {{ $product['sessions'] }} {{ trans_choice('занятие|занятия|занятий', $product['sessions']) }}
                    · срок {{ $product['validity_days'] }} {{ trans_choice('день|дня|дней', $product['validity_days']) }}
                  </p>
                  <div class="purchase-card__price">{{ number_format($product['price'], 0, '', ' ') }} ₽</div>
                  <label class="purchase-card__field">
                    <span>Дата начала абонемента</span>
                    <input type="date" name="starts_at" value="{{ old('starts_at', now()->format('Y-m-d')) }}" min="{{ now()->format('Y-m-d') }}" max="{{ now()->addMonths(3)->format('Y-m-d') }}" required>
                  </label>
                  <fieldset class="purchase-card__methods">
                    <legend class="purchase-card__methods-label">Способ оплаты</legend>
                    <label class="purchase-card__method">
                      <input type="radio" name="payment_method" value="smart" @checked(old('payment_method', 'smart') === 'smart')>
                      <span>Карта, ЮMoney и другие способы</span>
                    </label>
                    <label class="purchase-card__method">
                      <input type="radio" name="payment_method" value="sbp" @checked(old('payment_method') === 'sbp')>
                      <span>СБП — любой банк</span>
                    </label>
                  </fieldset>
                  <button type="submit" class="btn btn--solid btn--full">Оплатить</button>
                </form>
              @endforeach
            </div>
          </div>
        @endforeach

        <p class="purchase-note">Оплата проходит через защищённую форму ЮKassa. Для оплаты через СБП выберите «СБП — любой банк» — откроется список банков. После оплаты вы вернётесь на сайт — абонемент активируется автоматически. Оплачивая абонемент, вы принимаете условия <a href="{{ route('offer.show') }}" target="_blank" rel="noopener">договора-оферты</a>.</p>

        <a href="{{ route('account') }}" class="btn btn--line" style="margin-top: 8px">Вернуться в личный кабинет</a>
      </div>
    </div>
  </section>
@endsection
