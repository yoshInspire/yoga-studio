@php
  $cardClass = match ($slot['status']) {
      'full' => 'gridsched__card--full',
      'cancelled' => 'gridsched__card--cancelled',
      default => '',
  };
  if ($slot['user_booked'] ?? false) {
      $cardClass .= ' gridsched__card--booked';
  }
@endphp
<button type="button"
        class="gridsched__card {{ trim($cardClass) }}"
        data-session='@json($slot, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)'
        aria-label="{{ $slot['title'] }}, {{ $slot['time_range'] }}">
  <span class="gridsched__card-time">
    {{ $slot['time_range'] }}
    <span class="gridsched__card-duration">{{ $slot['duration_minutes'] }} мин</span>
  </span>
  <span class="gridsched__card-title">{{ $slot['direction'] ?: $slot['topic'] ?: $slot['title'] }}</span>
  @if(!empty($slot['direction']) && !empty($slot['topic']))
    <span class="gridsched__card-topic">{{ $slot['topic'] }}</span>
  @endif
  <span class="gridsched__card-trainer">{{ $slot['trainer'] }}</span>
  <span class="gridsched__card-seats">
    @if($slot['status'] === 'cancelled')
      <strong>Отменено</strong>
    @elseif($slot['status'] === 'full')
      <strong>Мест нет</strong>
    @else
      <strong>Свободно: {{ $slot['free'] }}</strong> из {{ $slot['total'] }}
    @endif
  </span>
</button>
