@props(['theme' => 'dark', 'class' => ''])

<img
  src="{{ asset($theme === 'light' ? 'images/logo-footer.png' : 'images/logo-header.png') }}"
  alt="ЭКО YOGA — студия Ирины Коленцевой"
  class="logo__img {{ $class }}"
  width="1024"
  height="1024"
  decoding="async"
/>
