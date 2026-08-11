<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientMailingLog extends Model
{
    public const TYPE_DAILY_EVENING = 'daily_evening';

    public const TYPE_WEEKLY_SCHEDULE = 'weekly_schedule';

    public const TYPE_CUSTOM = 'custom';

    public const TYPE_BIRTHDAY = 'birthday';

    /** Памятка «К вашему визиту» — один раз при первом бронировании. */
    public const TYPE_WELCOME = 'welcome_visit';

    /** Публикация новости — ключ `news:<id>`, один раз на новость. */
    public const TYPE_NEWS = 'news';

    protected $fillable = [
        'user_id',
        'type',
        'mailing_key',
        'sent_at',
    ];

    /**
     * `mailing_key` — строка, а не дата, и каста у неё быть не должно.
     *
     * Каст `date` сводил ключ произвольной рассылки к дате и превращал UNIQUE
     * (user_id, type, mailing_key) в мину: второе оповещение за день падало
     * после отправки первому клиенту. См. миграцию от 11.08.2026.
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
