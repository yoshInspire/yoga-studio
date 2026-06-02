@extends('layouts.site')

@section('title', 'Расписание — Студия йоги Ирины Коленцевой')

@section('content')
  <section class="section page-placeholder">
    <div class="container">
      <p class="eyebrow">Расписание</p>
      <h1 class="section__title">Расписание и запись</h1>
      <p class="section__desc" style="max-width: 560px">
        Раздел в разработке. Запись на занятия будет доступна в личном кабинете после входа.
      </p>
      <div style="margin-top: 32px; display: flex; gap: 14px; flex-wrap: wrap">
        <a href="{{ route('login') }}" class="btn btn--solid">Личный кабинет</a>
        <a href="{{ route('home') }}" class="btn btn--line">На главную</a>
      </div>
    </div>
  </section>
@endsection
