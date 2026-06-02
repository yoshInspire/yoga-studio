@extends('layouts.site')

@section('title', 'Направления студии — Студия йоги Ирины Коленцевой')

@section('content')
  <section class="section page-placeholder">
    <div class="container">
      <p class="eyebrow">Направления</p>
      <h1 class="section__title">Направления студии</h1>
      <p class="section__desc" style="max-width: 560px">
        В студии представлены более 10 направлений йоги для гостей разного уровня подготовки.
        Полный каталог направлений будет опубликован здесь.
      </p>
      <a href="{{ route('home') }}#directions" class="btn btn--line" style="margin-top: 32px">Вернуться на главную</a>
    </div>
  </section>
@endsection
