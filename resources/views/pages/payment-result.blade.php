@extends('layouts.site')

@section('title', 'Результат оплаты — Студия йоги Ирины Коленцевой')
@section('robots', 'noindex, nofollow')

@section('content')
  <section class="section lk">
    <div class="container">
      <div class="lk__content reveal payment-result" style="max-width: 720px; margin: 0 auto;">
        @if ($success)
          <div class="payment-result__icon payment-result__icon--ok" aria-hidden="true">✓</div>
          <h1 class="lk__title">Оплата прошла успешно</h1>
          <p class="lk__lead">Абонемент «{{ $payment->description }}» добавлен в ваш личный кабинет. Можно бронировать места на занятия.</p>
          <div class="payment-result__actions">
            <a href="{{ route('account') }}" class="btn btn--solid">Перейти в кабинет</a>
            <a href="{{ route('schedule') }}" class="btn btn--line">Забронировать место</a>
          </div>
        @elseif (!empty($pending))
          <div class="payment-result__icon payment-result__icon--wait" aria-hidden="true">…</div>
          <h1 class="lk__title">Платёж обрабатывается</h1>
          <p class="lk__lead">Если оплата прошла, абонемент появится в кабинете в течение нескольких минут. Обновите страницу или вернитесь позже.</p>
          <div class="payment-result__actions">
            <a href="{{ $returnUrl }}" class="btn btn--solid">Обновить статус</a>
            <a href="{{ route('account') }}" class="btn btn--line">В личный кабинет</a>
          </div>
        @else
          <div class="payment-result__icon payment-result__icon--fail" aria-hidden="true">×</div>
          <h1 class="lk__title">Оплата не завершена</h1>
          <p class="lk__lead">Платёж отменён или не прошёл. Абонемент не был начислен — попробуйте ещё раз или свяжитесь со студией.</p>
          <div class="payment-result__actions">
            <a href="{{ route('purchase.index') }}" class="btn btn--solid">Выбрать тариф снова</a>
            <a href="{{ route('account') }}" class="btn btn--line">В личный кабинет</a>
          </div>
        @endif
      </div>
    </div>
  </section>
@endsection
