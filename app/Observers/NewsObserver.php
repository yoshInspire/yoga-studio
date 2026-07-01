<?php

namespace App\Observers;

use App\Models\News;
use App\Services\NewsNotificationService;

class NewsObserver
{
    public function __construct(
        private NewsNotificationService $notifications,
    ) {}

    public function saved(News $news): void
    {
        $this->notifications->notifyClientsIfNeeded($news);
    }
}
