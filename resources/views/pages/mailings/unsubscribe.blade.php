@extends('layouts.site')

@section('title', 'Рассылки — Студия йоги Ирины Коленцевой')

@section('content')
  <section class="section legal">
    <div class="container container--narrow">
      <p class="eyebrow">Рассылки</p>

      @if ($state === 'confirm')
        <h1 class="section__title legal__title">Отписаться от рассылок?</h1>
        <div class="legal__body">
          <p>Мы перестанем присылать вам:</p>
          <ul>
            <li>анонс расписания на новую неделю;</li>
            <li>новости и объявления студии;</li>
            <li>вечернее напоминание, когда занятий на завтра нет;</li>
            <li>поздравление с днём рождения.</li>
          </ul>
          <p>
            Письма о ваших собственных записях — напоминание накануне занятия, отмену
            занятия, окончание абонемента и вход в кабинет — вы продолжите получать:
            это часть услуги, а не рассылка.
          </p>
          <form action="{{ $unsubscribeUrl }}" method="post" class="legal__actions">
            @csrf
            <button type="submit" class="btn btn--solid">Отписаться</button>
          </form>
        </div>
      @elseif ($state === 'already')
        <h1 class="section__title legal__title">Вы уже отписаны</h1>
        <div class="legal__body">
          <p>Рассылки студии на этот адрес не приходят.</p>
          <p><a href="{{ $resubscribeUrl }}" class="btn btn--line">Вернуть подписку</a></p>
        </div>
      @elseif ($state === 'done')
        <h1 class="section__title legal__title">Готово, вы отписаны</h1>
        <div class="legal__body">
          <p>
            Больше рассылок не будет. Письма о ваших записях, отменах занятий и абонементе
            продолжат приходить — без них вы не узнаете, что занятие отменилось.
          </p>
          <p>Передумали?</p>
          <p><a href="{{ $resubscribeUrl }}" class="btn btn--line">Вернуть подписку</a></p>
        </div>
      @else
        <h1 class="section__title legal__title">Подписка возвращена</h1>
        <div class="legal__body">
          <p>Рассылки студии снова будут приходить на этот адрес.</p>
          <p><a href="{{ route('home') }}" class="btn btn--solid">На главную</a></p>
        </div>
      @endif
    </div>
  </section>
@endsection
