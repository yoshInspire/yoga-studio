@extends('layouts.site')

@section('title', 'Личный кабинет — Студия йоги Ирины Коленцевой')

@section('content')
  <section class="section page-placeholder">
    <div class="container">
      <p class="eyebrow">Личный кабинет</p>
      <h1 class="section__title">Вход и регистрация</h1>
      <p class="section__desc" style="max-width: 560px">
        Авторизация и запись на занятия появятся на следующем этапе разработки.
      </p>
      <a href="{{ route('schedule') }}" class="btn btn--solid" style="margin-top: 32px">Смотреть расписание</a>
    </div>
  </section>
@endsection
