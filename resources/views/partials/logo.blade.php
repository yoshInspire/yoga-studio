@props(['theme' => 'dark', 'class' => '', 'variant' => 'image'])

@if ($variant === 'header')
  <span class="logo__stack {{ $class }}">
    <img
      src="{{ asset('images/logo-header-mark.png') }}"
      alt="ЭКО YOGA"
      class="logo__img logo__img--mark"
      decoding="async"
    />
    <span class="logo__name">Irina Kolentseva</span>
  </span>
@else
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
@endif
