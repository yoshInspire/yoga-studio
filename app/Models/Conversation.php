<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Переписка клиента со студией. На одного клиента — одна.
 */
#[Fillable([
    'user_id',
    'last_message_at',
])]
class Conversation extends Model
{
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /** Клиент, которому принадлежит переписка. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Последнее сообщение — для списка переписок в админке. */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /** Непрочитанные сообщения клиента (их читает студия). */
    public function unreadFromClient(): HasMany
    {
        return $this->messages()->whereNull('read_at')->where('sender_id', $this->user_id);
    }

    /** Непрочитанные сообщения студии (их читает клиент). */
    public function unreadFromStudio(): HasMany
    {
        return $this->messages()->whereNull('read_at')->where('sender_id', '!=', $this->user_id);
    }

    /**
     * Сначала те, где недавно писали. Переписки без сообщений — в конец:
     * они появляются, когда админ открыл клиента, но ещё ничего не отправил.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByRaw('last_message_at IS NULL')->orderByDesc('last_message_at')->orderByDesc('id');
    }
}
