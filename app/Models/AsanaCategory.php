<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'sort',
])]
class AsanaCategory extends Model
{
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    /** Сколько поз лежит в разделе. */
    public function asanaCount(): int
    {
        return Asana::query()->where('category', $this->name)->count();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('name');
    }
}
