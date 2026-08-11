@php
  // Ссылки на случай, когда JS не отработал: каждый чип — обычная ссылка с уже
  // пересчитанным набором направлений. Скрипт эти же адреса и читает, поэтому
  // логика переключения живёт в одном месте — здесь.
  $filterUrl = function (array $slugs) use ($offset, $rescheduleFrom) {
      return route('schedule', array_filter([
          'offset' => $offset > 0 ? $offset : null,
          'reschedule' => ($rescheduleFrom ?? null)?->id,
          'directions' => $slugs ? implode(',', $slugs) : null,
      ], fn ($value) => $value !== null));
  };
  $toggled = function (string $slug) use ($directionOptions, $selectedDirections) {
      $next = in_array($slug, $selectedDirections, true)
          ? array_diff($selectedDirections, [$slug])
          : array_merge($selectedDirections, [$slug]);

      return array_values(array_filter(
          array_column($directionOptions, 'slug'),
          fn (string $item) => in_array($item, $next, true),
      ));
  };
@endphp

<p class="sched-filter__label" id="schedFilterLabel">Направления</p>
<div class="sched-filter__row">
  <div class="sched-filter__chips" role="group" aria-labelledby="schedFilterLabel" data-scroll-fade>
    <a class="sched-filter__chip {{ $selectedDirections ? '' : 'is-active' }}"
       href="{{ $filterUrl([]) }}"
       data-dir-filter=""
       @if(! $selectedDirections) aria-current="true" @endif>Все</a>
    @foreach ($directionOptions as $option)
      @php $isActive = in_array($option['slug'], $selectedDirections, true); @endphp
      <a class="sched-filter__chip {{ $isActive ? 'is-active' : '' }}"
         href="{{ $filterUrl($toggled($option['slug'])) }}"
         data-dir-filter="{{ $option['slug'] }}"
         @if($isActive) aria-current="true" @endif>{{ $option['title'] }}</a>
    @endforeach
  </div>
</div>
