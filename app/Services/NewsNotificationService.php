<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\News;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Уведомления клиентов о публикации новости на сайте (почта и Telegram).
 */
class NewsNotificationService
{
    public function __construct(
        private NotificationService $notifications,
    ) {}

    /**
     * Отправить уведомления, если новость опубликована и ещё не рассылалась.
     *
     * @return int|null Число получателей или null, если рассылка не требовалась.
     */
    public function notifyClientsIfNeeded(News $news): ?int
    {
        if (! $this->isEnabled() || ! $this->shouldNotify($news)) {
            return null;
        }

        $message = $this->buildMessage($news);
        $sent = 0;

        $this->eligibleClients()->each(function (User $user) use ($message, $news, &$sent) {
            $this->notifications->notifyUser(
                $user,
                $message['heading'],
                $message['lines'],
                $message['subject'],
                type: 'news',
                payload: ['news_slug' => $news->slug],
            );
            $sent++;
        });

        $news->forceFill(['notifications_sent_at' => now()])->saveQuietly();

        return $sent;
    }

    /**
     * Новости, у которых наступила дата публикации, но уведомления ещё не отправлены.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, News>
     */
    public function pendingPublications(?Carbon $on = null)
    {
        $on ??= now();

        return News::query()
            ->where('is_published', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $on)
            ->whereNull('notifications_sent_at')
            ->orderBy('published_at')
            ->get();
    }

    public function shouldNotify(News $news): bool
    {
        return $news->is_published
            && $news->published_at !== null
            && $news->published_at->lte(now())
            && $news->notifications_sent_at === null;
    }

    /**
     * @return array{heading: string, subject: string, lines: list<string>}
     */
    public function buildMessage(News $news): array
    {
        $lines = [
            'Здравствуйте!',
            'На сайте студии опубликована новость:',
            '«'.$news->title.'»',
        ];

        $excerpt = trim($news->readableExcerpt());
        if ($excerpt !== '') {
            $lines[] = $excerpt;
        }

        $lines[] = '👉 '.$this->newsUrl($news);

        return [
            'heading' => '📰 Новость на сайте',
            'subject' => 'Новость: '.$news->title,
            'lines' => $lines,
        ];
    }

    public function newsUrl(News $news): string
    {
        return route('news.show', $news, absolute: true);
    }

    /**
     * Сколько клиентов получат уведомление о публикации.
     *
     * Нужно показать до отправки: в приложении «Опубликовать» нажимают
     * пальцем, и человек должен видеть, скольким уйдут письма.
     */
    public function recipientsCount(): int
    {
        return $this->isEnabled() ? $this->eligibleClients()->count() : 0;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function eligibleClients()
    {
        return User::query()
            ->where('role', UserRole::Client)
            ->whereNotNull('offer_accepted_at')
            ->where(function ($query) {
                $query->whereNotNull('email')
                    ->orWhereNotNull('telegram_id');
            })
            ->orderBy('id');
    }

    private function isEnabled(): bool
    {
        return (bool) config('studio.news_notifications.enabled', true);
    }
}
