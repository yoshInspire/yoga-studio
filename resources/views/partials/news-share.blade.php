@php
  /**
   * «Поделиться новостью»: ссылки открывают мессенджер или почту с готовым
   * текстом — заголовок и адрес новости. Работают без JS; JS нужен только
   * кнопке «Скопировать ссылку».
   */
  $shareUrl = route('news.show', $news);
  $shareTitle = $news->title;
  $shareText = $shareTitle."\n".$shareUrl;

  $links = [
      [
          'label' => 'Telegram',
          'href' => 'https://t.me/share/url?url='.rawurlencode($shareUrl).'&text='.rawurlencode($shareTitle),
          'external' => true,
          'icon' => '<path d="M15 10l-4 4l6 6l4 -16l-18 7l4 2l2 6l3 -4" />',
      ],
      [
          'label' => 'WhatsApp',
          'href' => 'https://wa.me/?text='.rawurlencode($shareText),
          'external' => true,
          'icon' => '<path d="M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9l-5.05 .9" /><path d="M9 10a.5 .5 0 0 0 1 0v-1a.5 .5 0 0 0 -1 0v1a5 5 0 0 0 5 5h1a.5 .5 0 0 0 0 -1h-1a.5 .5 0 0 0 0 1" />',
      ],
      [
          'label' => 'ВКонтакте',
          'href' => 'https://vk.com/share.php?url='.rawurlencode($shareUrl).'&title='.rawurlencode($shareTitle),
          'external' => true,
          'icon' => '<path d="M14 19h-4a8 8 0 0 1 -8 -8v-5h4v5a4 4 0 0 0 4 4h0v-9h4v4.5l.03 0a4.531 4.531 0 0 0 3.97 -4.5h4l-.342 1.711a6.858 6.858 0 0 1 -3.658 4.789h0a5.34 5.34 0 0 1 3.566 4.111l.434 2.389h0h-4a4.531 4.531 0 0 0 -3.97 -4.496v4.5z" />',
      ],
      [
          'label' => 'Почта',
          'href' => 'mailto:?subject='.rawurlencode($shareTitle).'&body='.rawurlencode($news->readableExcerpt()."\n\n".$shareUrl),
          'external' => false,
          'icon' => '<path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z" /><path d="M3 7l9 6l9 -6" />',
      ],
      [
          // «sms:?&body=» понимают и Android, и iPhone.
          'label' => 'SMS',
          'href' => 'sms:?&body='.rawurlencode($shareText),
          'external' => false,
          'icon' => '<path d="M8 9h8" /><path d="M8 13h6" /><path d="M18 4a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-5l-5 3v-3h-2a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h12z" />',
      ],
  ];
@endphp

<div class="news-share" data-news-share data-share-url="{{ $shareUrl }}" data-share-title="{{ $shareTitle }}">
  <p class="news-share__title">Поделитесь новостью с друзьями</p>
  <div class="news-share__buttons">
    @foreach ($links as $link)
      <a href="{{ $link['href'] }}"
         class="news-share__btn"
         @if ($link['external']) target="_blank" rel="noopener" @endif
         aria-label="Поделиться: {{ $link['label'] }}">
        <svg class="news-share__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $link['icon'] !!}</svg>
        <span>{{ $link['label'] }}</span>
      </a>
    @endforeach
    <button type="button" class="news-share__btn" data-share-copy>
      <svg class="news-share__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 15l6 -6" /><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" /><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" /></svg>
      <span data-share-copy-label>Скопировать ссылку</span>
    </button>
  </div>
</div>
