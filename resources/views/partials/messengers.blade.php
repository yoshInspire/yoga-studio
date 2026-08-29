@php
  /**
   * Иконки мессенджеров студии: Telegram и WhatsApp.
   *
   * $modifier — BEM-модификатор блока (например, 'footer' для тёмного подвала).
   * Пустая ссылка в config('studio.socials') просто убирает свою иконку.
   */
  $telegram = config('studio.socials.telegram');
  $whatsapp = config('studio.socials.whatsapp');
  $modifier = $modifier ?? null;
@endphp

@if (filled($telegram) || filled($whatsapp))
  <div class="messengers{{ $modifier ? ' messengers--'.$modifier : '' }}">
    @if (filled($telegram))
      <a href="{{ $telegram }}" class="messengers__link" target="_blank" rel="noopener" aria-label="Telegram студии" title="Telegram">
        <svg class="messengers__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M15 10l-4 4l6 6l4 -16l-18 7l4 2l2 6l3 -4" />
        </svg>
      </a>
    @endif
    @if (filled($whatsapp))
      <a href="{{ $whatsapp }}" class="messengers__link" target="_blank" rel="noopener" aria-label="WhatsApp студии" title="WhatsApp">
        <svg class="messengers__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9l-5.05 .9" />
          <path d="M9 10a.5 .5 0 0 0 1 0v-1a.5 .5 0 0 0 -1 0v1a5 5 0 0 0 5 5h1a.5 .5 0 0 0 0 -1h-1a.5 .5 0 0 0 0 1" />
        </svg>
      </a>
    @endif
  </div>
@endif
