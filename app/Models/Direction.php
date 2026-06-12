<?php

namespace App\Models;

use App\Support\DirectionMedia;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug',
    'num',
    'sort_order',
    'title',
    'lead',
    'tag',
    'cover_image_path',
    'gallery_paths',
    'body',
    'benefits',
    'is_published',
])]
class Direction extends Model
{
    protected function casts(): array
    {
        return [
            'gallery_paths' => 'array',
            'body' => 'array',
            'benefits' => 'array',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    public function coverUrl(): ?string
    {
        return filled($this->cover_image_path)
            ? DirectionMedia::url($this->cover_image_path)
            : null;
    }

    /**
     * @return list<string>
     */
    public function slidePaths(): array
    {
        return array_values(array_filter(array_merge(
            [$this->cover_image_path],
            $this->gallery_paths ?? [],
        )));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function seoDescription(): string
    {
        return \Illuminate\Support\Str::limit(strip_tags($this->lead ?? ''), 160);
    }
}
