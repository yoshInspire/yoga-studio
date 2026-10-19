<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'family_group_id',
    'inviter_id',
    'code',
    'expires_at',
    'accepted_by_id',
    'accepted_at',
    'revoked_at',
])]
class FamilyInvite extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(FamilyGroup::class, 'family_group_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /** Приглашение ещё можно принять: не принято, не отозвано и не истекло. */
    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /** Код для показа: «K7MQ2P9X» → «K7MQ-2P9X», так его легче продиктовать. */
    public function formattedCode(): string
    {
        return substr($this->code, 0, 4).'-'.substr($this->code, 4);
    }
}
