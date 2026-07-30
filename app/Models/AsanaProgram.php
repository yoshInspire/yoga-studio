<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'folder_id',
    'title',
    'note',
    'sort',
])]
class AsanaProgram extends Model
{
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(AsanaFolder::class, 'folder_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AsanaProgramItem::class, 'program_id')->ordered();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }
}
