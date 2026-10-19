{{-- Раздел «Семья» личного кабинета. Данные — FamilyService::overview(). --}}
@php
  $group = $family['group'];
  $newInviteId = session('family_new_invite');
@endphp
<div class="lk__panel is-hidden" data-panel="family">
  <h1 class="lk__title">Семья</h1>
  <p class="lk__lead">
    Соберите семью, и владелец абонемента сможет разрешить остальным записываться по нему —
    занятие спишется с его абонемента. Доступ к каждому абонементу открывает и закрывает только владелец.
    В семье до {{ $family['max_members'] }} человек.
  </p>

  @if (session('family_status'))
    <div class="auth__alert auth__alert--ok lk__alert">{{ session('family_status') }}</div>
  @endif
  @if ($errors->has('family'))
    <div class="auth__alert auth__alert--error lk__alert">{{ $errors->first('family') }}</div>
  @endif

  @if ($group === null)
    <div class="family-empty">
      <form action="{{ route('account.family.invites.store') }}" method="post">
        @csrf
        <button type="submit" class="btn btn--solid">Пригласить в семью</button>
      </form>
      @if ($family['can_add_child'])
        @include('partials.family-child-form', ['child' => null])
      @endif
      <form action="{{ route('family.join') }}" method="get" class="family-code">
        <label class="lk-profile-form__label" for="family-code">Есть код приглашения?</label>
        <div class="family-code__row">
          <input class="lk-profile-form__input" id="family-code" name="code" placeholder="K7MQ-2P9X" autocomplete="off" required />
          <button type="submit" class="btn btn--line">Открыть</button>
        </div>
      </form>
    </div>
  @else
    {{-- Состав --}}
    <h2 class="family__heading">Участники · {{ $group['members_count'] }} из {{ $family['max_members'] }}</h2>
    <div class="lk-list">
      @foreach ($group['members'] as $m)
        <div class="lk-row family-member">
          <span class="family-member__avatar" aria-hidden="true">
            @if ($m['avatar_url'])
              <img src="{{ $m['avatar_url'] }}" alt="" />
            @else
              {{ $m['initials'] }}
            @endif
          </span>
          <div class="lk-row__main">
            <h3>{{ $m['name'] }}@if ($m['is_me']) <span class="family-member__me">(вы)</span>@endif</h3>
            <p>
              @if ($m['is_child'] ?? false)
                Ребёнок · представитель: {{ $m['guardian_name'] }}@if ($m['child']) · {{ $m['child']['birth_date_label'] }}@endif
              @else
                {{ $m['is_owner'] ? 'Глава семьи' : 'В семье с '.$m['joined_at'] }}
              @endif
            </p>
            @if ($m['child'] ?? null)
              @include('partials.family-child-form', ['child' => $m['child']])
            @endif
          </div>
          @if ($group['is_owner'] && ! $m['is_me'])
            <div class="lk-row__actions">
              @unless ($m['is_child'] ?? false)
                <form action="{{ route('account.family.members.owner', $m['id']) }}" method="post"
                      data-confirm="Сделать {{ $m['name'] }} главой семьи? Приглашать и исключать участников сможет только он(а).">
                  @csrf
                  <button type="submit" class="btn btn--ghost">Сделать главой</button>
                </form>
              @endunless
              <form action="{{ route('account.family.members.destroy', $m['id']) }}" method="post"
                    data-confirm="Исключить {{ $m['name'] }} из семьи? Будущие записи по абонементам семьи отменятся, занятия вернутся владельцам.">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn--ghost">Исключить</button>
              </form>
            </div>
          @endif
        </div>
      @endforeach
    </div>

    @if ($group['can_add_child'] ?? false)
      <div style="margin: -8px 0 24px">
        @include('partials.family-child-form', ['child' => null])
      </div>
    @endif

    {{-- Приглашения — только у главы --}}
    @if ($group['is_owner'])
      <h2 class="family__heading">Приглашения</h2>
      @forelse ($group['invites'] as $invite)
        <div class="family-invite {{ $newInviteId === $invite['id'] ? 'is-new' : '' }}">
          <div class="family-invite__body">
            <span class="family-invite__code">{{ $invite['code'] }}</span>
            <span class="family-invite__meta">одноразовое, действует до {{ $invite['expires_at'] }}</span>
            <input class="lk-profile-form__input family-invite__link" value="{{ $invite['url'] }}" readonly aria-label="Ссылка-приглашение" />
          </div>
          <div class="family-invite__actions">
            <button type="button" class="btn btn--solid" data-copy="{{ $invite['message'] }}">Скопировать приглашение</button>
            <button type="button" class="btn btn--line" data-share="{{ $invite['message'] }}" data-share-url="{{ $invite['url'] }}" hidden>Поделиться</button>
            <form action="{{ route('account.family.invites.destroy', $invite['id']) }}" method="post">
              @csrf
              @method('DELETE')
              <button type="submit" class="btn btn--ghost">Отозвать</button>
            </form>
          </div>
        </div>
      @empty
        <p class="lk__empty">Неиспользованных приглашений нет.</p>
      @endforelse
      @if ($group['can_invite'])
        <form action="{{ route('account.family.invites.store') }}" method="post" style="margin-bottom: 28px">
          @csrf
          <button type="submit" class="btn btn--solid">Новое приглашение</button>
        </form>
      @endif
    @endif

    {{-- Свои абонементы: кому открыт доступ --}}
    @if ($family['my_subscriptions'] !== [])
      <h2 class="family__heading">Мои абонементы для семьи</h2>
      <p class="family__note">Отметьте, кто может записываться по абонементу, и при желании ограничьте число занятий на весь срок (пусто — без ограничений). Сняли галочку — будущие записи этого человека по абонементу отменятся, занятия вернутся вам.</p>
      @foreach ($family['my_subscriptions'] as $sub)
        @php($limits = (array) $sub['limits'])
        <form action="{{ route('account.family.shares', $sub['id']) }}" method="post" class="family-share">
          @csrf
          @method('PUT')
          <div class="family-share__head">
            <span class="badge badge--{{ $sub['type'] === 'individual' ? 'indiv' : 'group' }}">{{ $sub['type_short'] }}</span>
            <span>осталось {{ $sub['sessions_remaining'] }} из {{ $sub['sessions_total'] }}, до {{ $sub['ends_at'] }}</span>
          </div>
          <div class="family-share__people">
            @foreach ($group['members'] as $i => $m)
              @continue($m['is_me'])
              <div class="family-share__person">
                <label class="auth__check">
                  <input type="checkbox" name="shares[{{ $i }}][user_id]" value="{{ $m['id'] }}" @checked(in_array($m['id'], $sub['shared_with'], true)) />
                  {{ $m['name'] }}
                </label>
                <label class="family-share__limit">
                  не больше
                  <input class="lk-profile-form__input" type="number" name="shares[{{ $i }}][limit]" min="1" max="{{ \App\Services\FamilyService::MAX_SHARE_LIMIT }}"
                         value="{{ $limits[(string) $m['id']] ?? '' }}" placeholder="—" inputmode="numeric" aria-label="Лимит занятий для {{ $m['name'] }}" />
                  занятий
                </label>
              </div>
            @endforeach
          </div>
          <button type="submit" class="btn btn--line">Сохранить доступ</button>
        </form>
      @endforeach
    @elseif ($group['members_count'] > 1)
      <p class="family__note">Абонементов, которые можно открыть семье, сейчас нет. Делиться можно групповыми и индивидуальными; «2 занятия в день» и мероприятия не делятся.</p>
    @endif

    <div class="family__footer">
      <form action="{{ route('account.family.leave') }}" method="post"
            data-confirm="Выйти из семьи? Ваши будущие записи по абонементам семьи и записи других по вашим абонементам отменятся, занятия вернутся владельцам.">
        @csrf
        <button type="submit" class="btn btn--ghost">Выйти из семьи</button>
      </form>
      @if ($group['is_owner'] && $group['members_count'] > 1)
        <form action="{{ route('account.family.dissolve') }}" method="post"
              data-confirm="Распустить семью? Все участники выйдут, записи по абонементам семьи отменятся.">
          @csrf
          @method('DELETE')
          <button type="submit" class="btn btn--ghost family__danger">Распустить семью</button>
        </form>
      @endif
    </div>
  @endif

  {{-- Чужие абонементы, открытые мне --}}
  @if ($family['shared_with_me'] !== [])
    <h2 class="family__heading">Абонементы семьи, открытые вам</h2>
    <p class="family__note">При бронировании выберите такой абонемент — занятие спишется с него.</p>
    <div class="subs">
      @foreach ($family['shared_with_me'] as $sub)
        <div class="sub {{ $sub['is_active'] ? '' : 'sub--inactive' }}">
          <div class="sub__head">
            <span class="badge badge--{{ $sub['type'] === 'individual' ? 'indiv' : 'group' }}">{{ $sub['type_short'] }}</span>
            <h3 class="sub__name">Владелец: {{ $sub['owner_name'] }}</h3>
          </div>
          <div class="sub__count"><strong>{{ $sub['sessions_remaining'] }}</strong> из {{ $sub['sessions_total'] }} занятий осталось</div>
          @if ($sub['limit'] !== null)
            <p class="sub__shared">Осталось вам: {{ $sub['limit_left'] }} из {{ $sub['limit'] }}</p>
          @endif
          <dl class="sub__dates">
            <div><dt>Начало</dt><dd>{{ $sub['starts_at'] }}</dd></div>
            <div><dt>Действует до</dt><dd>{{ $sub['ends_at'] }}</dd></div>
          </dl>
        </div>
      @endforeach
    </div>
  @endif
</div>
