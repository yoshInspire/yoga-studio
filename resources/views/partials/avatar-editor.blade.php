{{--
  Фотография профиля с загрузкой.

  Ждёт переменную $avatarUser. Стоит и в кабинете клиента, и в кабинете
  тренера, поэтому маршрут общий, а возврат — на ту же страницу.
--}}
@php
  $avatarUrl = $avatarUser->avatarUrl();
@endphp

<div class="avatar {{ $avatarUrl ? 'has-photo' : '' }}" data-avatar>
  <form
    action="{{ route('account.avatar.store') }}"
    method="post"
    enctype="multipart/form-data"
    class="avatar__form"
    data-avatar-form
  >
    @csrf
    <label class="avatar__pick" title="Загрузить фотографию">
      <img
        src="{{ $avatarUrl ?? '' }}"
        alt="Фотография профиля"
        class="avatar__img {{ $avatarUrl ? '' : 'is-hidden' }}"
        data-avatar-preview
      >
      <span class="avatar__initials {{ $avatarUrl ? 'is-hidden' : '' }}" data-avatar-initials>
        {{ $avatarUser->initials() }}
      </span>
      {{-- Подпись поверх кружка: на десктопе всплывает по наведению,
           на телефоне видна всегда — иначе о загрузке никто не догадается. --}}
      <span class="avatar__overlay" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M4 8h3l1.5-2h7L17 8h3v11H4z" />
          <circle cx="12" cy="13" r="3.2" />
        </svg>
      </span>
      <input
        type="file"
        name="photo"
        accept="image/jpeg,image/png,image/webp"
        class="avatar__input"
        data-avatar-input
      >
    </label>
  </form>

  @if ($avatarUrl)
    <form action="{{ route('account.avatar.destroy') }}" method="post" class="avatar__remove-form">
      @csrf
      @method('DELETE')
      <button type="submit" class="avatar__remove" title="Удалить фотографию" aria-label="Удалить фотографию">
        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4"
             stroke-linecap="round">
          <path d="M6 6l12 12M18 6L6 18" />
        </svg>
      </button>
    </form>
  @endif
</div>
