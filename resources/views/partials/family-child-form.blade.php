{{-- Форма профиля ребёнка без своего входа: создание ($child = null) и правка.
     Правила — FamilyService::childRules(). Согласие представителя — только при создании. --}}
@php
  $isNew = $child === null;
  $formKey = $isNew ? 'new' : (string) $child['id'];
  $reopened = session('family_child_form') === $formKey;
  $value = fn (string $field, $fallback = '') => $reopened ? old($field, $fallback) : $fallback;
  $visibleToTrainer = $reopened
      ? old('health_note_visible_to_trainer') === '1'
      : (bool) ($child['health_note_visible_to_trainer'] ?? true);
@endphp
<details class="family-child" @if ($reopened) open @endif>
  <summary class="btn {{ $isNew ? 'btn--line' : 'btn--ghost' }}">{{ $isNew ? 'Добавить ребёнка' : 'Изменить профиль' }}</summary>
  <form action="{{ $isNew ? route('account.family.children.store') : route('account.family.children.update', $child['id']) }}" method="post" class="family-child__form">
    @csrf
    @unless ($isNew)
      @method('PUT')
    @endunless
    @if ($isNew)
      <p class="family__note">Профиль для ребёнка без своего телефона: записываете его вы, уведомления о его занятиях приходят вам. Ребёнок считается в составе семьи.</p>
    @endif
    <div class="family-child__row">
      <label class="lk-profile-form__label">Имя
        <input class="lk-profile-form__input" name="first_name" value="{{ $value('first_name', $child['first_name'] ?? '') }}" maxlength="60" required />
      </label>
      <label class="lk-profile-form__label">Фамилия
        <input class="lk-profile-form__input" name="last_name" value="{{ $value('last_name', $child['last_name'] ?? '') }}" maxlength="60" required />
      </label>
    </div>
    <label class="lk-profile-form__label">Дата рождения
      <input class="lk-profile-form__input" type="date" name="birth_date" value="{{ $value('birth_date', $child['birth_date'] ?? '') }}" max="{{ now()->subDay()->format('Y-m-d') }}" required />
    </label>
    <label class="lk-profile-form__label">Заметка о здоровье <span class="family__optional">— необязательно</span>
      <textarea class="lk-profile-form__input" name="health_note" rows="3" maxlength="1000">{{ $value('health_note', $child['health_note'] ?? '') }}</textarea>
    </label>
    <input type="hidden" name="health_note_visible_to_trainer" value="0" />
    <label class="auth__check">
      <input type="checkbox" name="health_note_visible_to_trainer" value="1" @checked($visibleToTrainer) />
      Показывать заметку тренеру
    </label>
    @if ($isNew)
      {{-- Текст согласия Ирина пришлёт — пока ссылка на оферту и политику. --}}
      <label class="auth__check">
        <input type="checkbox" name="guardian_consent" value="1" required />
        <span>Я законный представитель ребёнка, принимаю за него <a href="{{ route('legal.offer') }}" target="_blank" rel="noopener">договор-оферту</a> и даю согласие на обработку его персональных данных по <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener">политике</a>.</span>
      </label>
    @endif
    <button type="submit" class="btn btn--solid">{{ $isNew ? 'Добавить' : 'Сохранить' }}</button>
  </form>
</details>
