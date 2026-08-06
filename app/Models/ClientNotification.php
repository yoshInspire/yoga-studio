<?php

namespace App\Models;

use App\Support\RussianDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'type',
    'title',
    'body',
    'payload',
    'read_at',
])]
class ClientNotification extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /** «6 августа, 20:00» — тот же формат дат, что на остальных экранах. */
    public function formattedDate(): string
    {
        return RussianDate::dayMonth($this->created_at).', '.$this->created_at->format('H:i');
    }
}
