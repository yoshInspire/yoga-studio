@php
  use App\Support\Seo;

  $seoTitle = trim($__env->yieldContent('title')) ?: config('seo.default_title');
  $seoDescription = trim($__env->yieldContent('meta_description')) ?: config('seo.default_description');
  $seoRobots = trim($__env->yieldContent('robots'));
  $seoCanonical = trim($__env->yieldContent('canonical')) ?: Seo::canonicalUrl();
  $seoOgImage = Seo::absoluteAsset(trim($__env->yieldContent('og_image')) ?: config('seo.default_og_image'));
  $seoOgType = trim($__env->yieldContent('og_type')) ?: 'website';
@endphp
<meta name="description" content="{{ $seoDescription }}" />
<meta name="yandex-verification" content="4d5bc6e9b8cf5eb3" />
@if ($seoRobots !== '')
  <meta name="robots" content="{{ $seoRobots }}" />
@endif
<link rel="canonical" href="{{ $seoCanonical }}" />
<meta property="og:site_name" content="{{ config('seo.site_name') }}" />
<meta property="og:locale" content="{{ config('seo.locale') }}" />
<meta property="og:type" content="{{ $seoOgType }}" />
<meta property="og:title" content="{{ $seoTitle }}" />
<meta property="og:description" content="{{ $seoDescription }}" />
<meta property="og:url" content="{{ $seoCanonical }}" />
<meta property="og:image" content="{{ $seoOgImage }}" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="{{ $seoTitle }}" />
<meta name="twitter:description" content="{{ $seoDescription }}" />
<meta name="twitter:image" content="{{ $seoOgImage }}" />
