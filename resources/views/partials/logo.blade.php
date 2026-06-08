@props(['theme' => 'dark', 'class' => ''])

@php
  $src = match ($theme) {
    'light' => 'images/logo-footer.png',
    'mark' => 'images/logo-header-mark.png',
    default => 'images/logo-header.png',
  };
@endphp

<img
  src="{{ asset($src) }}"
  alt="ЭКО YOGA — студия Ирины Коленцевой"
  class="logo__img {{ $class }}"
  decoding="async"
/>
