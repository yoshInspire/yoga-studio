@php
  use App\Enums\NewsReactionType;

  $summary = $summary ?? ['counts' => array_fill_keys(NewsReactionType::values(), 0), 'total' => 0, 'user_reaction' => null];
  $compact = $compact ?? false;
  $canReact = auth()->check() && auth()->user()->isClient();
  $loginUrl = route('login');
@endphp

<div
  class="news-reactions{{ $compact ? ' news-reactions--compact' : '' }}"
  data-news-reactions
  data-news-id="{{ $news->getKey() }}"
  data-action="{{ route('news.reactions.store', $news) }}"
  data-can-react="{{ $canReact ? '1' : '0' }}"
  data-login-url="{{ $loginUrl }}"
>
  @unless ($compact)
    <p class="news-reactions__label">Ваша реакция</p>
  @endunless

  <div class="news-reactions__buttons" role="group" @unless($compact) aria-label="Реакции на новость" @endunless>
    @foreach (NewsReactionType::cases() as $type)
      @php
        $count = (int) ($summary['counts'][$type->value] ?? 0);
        $isActive = ($summary['user_reaction'] ?? null) === $type->value;
      @endphp
      <button
        type="button"
        class="news-reactions__btn{{ $isActive ? ' is-active' : '' }}"
        data-reaction="{{ $type->value }}"
        aria-label="{{ $type->label() }}"
        title="{{ $type->label() }}"
        @unless($canReact) data-requires-login="1" @endunless
      >
        <span class="news-reactions__emoji" aria-hidden="true">{{ $type->emoji() }}</span>
        <span class="news-reactions__count" data-count="{{ $type->value }}">{{ $count > 0 ? $count : '' }}</span>
      </button>
    @endforeach
  </div>

  @unless ($compact)
    <p class="news-reactions__hint">
      @if ($canReact)
        Нажмите, чтобы поставить реакцию. Повторное нажатие снимет её.
      @else
        <a href="{{ $loginUrl }}">Войдите в личный кабинет</a>, чтобы поставить реакцию.
      @endif
    </p>
  @endunless
</div>
