<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'parent_id',
    'name',
    'sort',
])]
class AsanaFolder extends Model
{
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->ordered();
    }

    public function programs(): HasMany
    {
        return $this->hasMany(AsanaProgram::class, 'folder_id')->ordered();
    }

    /** Цепочка от корня до текущей папки — для «хлебных крошек». */
    public function breadcrumbs(): array
    {
        $chain = [];
        $folder = $this;

        // Защита от зацикливания на случай битой иерархии.
        $guard = 0;

        while ($folder !== null && $guard < 20) {
            array_unshift($chain, $folder);
            $folder = $folder->parent;
            $guard++;
        }

        return $chain;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('name');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }
}
