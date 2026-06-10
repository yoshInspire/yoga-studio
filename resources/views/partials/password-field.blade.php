<div class="password-field">
  <input
    type="password"
    id="{{ $id }}"
    name="{{ $name }}"
    placeholder="{{ $placeholder }}"
    @if(!empty($autocomplete)) autocomplete="{{ $autocomplete }}" @endif
    @if(!empty($form)) form="{{ $form }}" @endif
    @if(!empty($required)) required @endif
  />
  <button
    type="button"
    class="password-field__toggle"
    aria-label="Показать пароль"
    aria-pressed="false"
    data-password-toggle
  >
    <svg class="password-field__icon password-field__icon--show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
      <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/>
      <circle cx="12" cy="12" r="3"/>
    </svg>
    <svg class="password-field__icon password-field__icon--hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true" hidden>
      <path d="M10.7 10.7a3 3 0 0 0 4.2 4.2"/>
      <path d="M6.6 6.6C4.5 8.1 2.8 10.4 2 12s3.5 7 10 7c1.6 0 3.1-.4 4.4-1"/>
      <path d="M9.9 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.7 17.7 0 0 1-2.3 3.4"/>
      <path d="M14.1 14.1 5.9 22.3"/>
      <path d="M2 2l20 20"/>
    </svg>
  </button>
</div>
