@extends('layouts.site')

@section('title', $direction->title.' — йога в студии Ирины Коленцевой, Коньково')
@section('meta_description', $direction->seoDescription())
@if ($direction->coverUrl())
  @section('og_image', $direction->coverUrl())
@endif

@section('content')
  <section class="section directions directions-page">
    <div class="container container--narrow">
      <p class="eyebrow reveal">
        <a href="{{ route('directions') }}" class="news-article__back">← Все направления</a>
      </p>
    </div>

    @include('partials.direction-detail', ['direction' => $direction])
  </section>
@endsection

@push('head')
  @php
    use App\Support\Seo;

    $pageUrl = route('directions.show', $direction);
    $breadcrumb = Seo::breadcrumbJsonLd([
      ['name' => 'Главная', 'url' => route('home')],
      ['name' => 'Направления', 'url' => route('directions')],
      ['name' => $direction->title, 'url' => $pageUrl],
    ]);
  @endphp
  <script type="application/ld+json">{!! json_encode($breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush
