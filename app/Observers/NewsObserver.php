<?php

namespace App\Observers;

use App\Models\News;
use App\Services\NewsNotificationService;
use App\Support\ImageThumbnailer;

class NewsObserver
{
    public function __construct(
        private NewsNotificationService $notifications,
    ) {}

    public function saved(News $news): void
    {
        $this->syncThumbnail($news);

        $this->notifications->notifyClientsIfNeeded($news);
    }

    /**
     * Держать уменьшенную копию картинки в согласии с оригиналом.
     *
     * Делаем при сохранении, а не при показе: уменьшение снимка на 1920 px —
     * это заметная работа, и запросу на чтение ленты она не по карману.
     */
    private function syncThumbnail(News $news): void
    {
        // Сравниваем с исходным значением, а не смотрим на `wasChanged()`:
        // на вставке Eloquent список изменений не заполняет (`syncChanges()`
        // есть только в `performUpdate`), а `wasRecentlyCreated` остаётся
        // взведённым и у последующих сохранений того же объекта.
        // К моменту события `saved` исходные значения ещё не синхронизированы,
        // у новой записи их попросту нет.
        $previous = $news->getOriginal('image_path');
        $current = $news->image_path;

        if ($previous === $current) {
            return;
        }

        if (is_string($previous) && $previous !== '') {
            ImageThumbnailer::forget($previous);
        }

        if (is_string($current) && $current !== '') {
            ImageThumbnailer::ensure($current);
        }
    }
}
