@php
  $cabinet = auth()->check() ? auth()->user()->publicCabinetLink() : ['url' => route('login'), 'label' => 'Личный кабинет'];
@endphp

<a href="{{ route('schedule') }}" class="header-icon" aria-label="Расписание" title="Расписание">
  <svg class="header-icon__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <rect x="3" y="4" width="18" height="18" rx="2.5" />
    <path d="M16 2v4M8 2v4M3 10h18" />
    <path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01" />
  </svg>
</a>

@if ($cabinet)
  <a href="{{ $cabinet['url'] }}" class="header-icon header-icon--solid" aria-label="{{ $cabinet['label'] }}" title="{{ $cabinet['label'] }}">
    <svg class="header-icon__svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="12" cy="8" r="4" />
      <path d="M5 20c0-3.314 3.134-6 7-6s7 2.686 7 6" />
    </svg>
  </a>
@endif
