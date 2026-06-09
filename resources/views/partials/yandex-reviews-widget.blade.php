@php
  $orgId = config('studio.yandex_maps_org_id');
  $profileUrl = config('studio.yandex_profile_url');
@endphp

<div class="reviews__widget reveal">
  <iframe
    class="reviews__iframe"
    src="https://yandex.ru/maps-reviews-widget/{{ $orgId }}?comments"
    title="Отзывы студии на Яндекс.Картах"
    loading="lazy"
  ></iframe>
</div>
<p class="reviews__source reveal">
  <a href="{{ $profileUrl }}" target="_blank" rel="noopener noreferrer">Все отзывы на Яндекс.Картах</a>
</p>
