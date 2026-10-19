<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Семейная группа. Вся логика — в `FamilyService`, модель только хранит.
 */
#[Fillable(['owner_id'])]
class FamilyGroup extends Model
{
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** Участники вместе с главой, первым — самый давний. */
    public function members(): HasMany
    {
        return $this->hasMany(FamilyMember::class)->orderBy('joined_at')->orderBy('id');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(FamilyInvite::class);
    }
}
