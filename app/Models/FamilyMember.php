<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['family_group_id', 'user_id', 'role', 'joined_at'])]
class FamilyMember extends Model
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_MEMBER = 'member';

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(FamilyGroup::class, 'family_group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Оба в одной семье. */
    public static function inSameFamily(User $a, User $b): bool
    {
        return self::query()
            ->whereIn('user_id', [$a->id, $b->id])
            ->select('family_group_id')
            ->groupBy('family_group_id')
            ->havingRaw('count(*) = 2')
            ->exists();
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }
}
