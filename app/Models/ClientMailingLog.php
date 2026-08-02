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

    protected $fillable = [
        'user_id',
        'type',
        'mailing_key',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'mailing_key' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
