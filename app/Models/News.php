<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable([
    'title',
    'excerpt',
    'body',
    'image_path',
    'is_published',
    'published_at',
])]
class News extends Model
{
    protected $table = 'news';

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function formattedDate(): string
    {
        return ($this->published_at ?? $this->created_at)->translatedFormat('d F Y');
    }

    public function readableExcerpt(): string
    {
        if (filled($this->excerpt)) {
            return $this->excerpt;
        }

        return Str::limit(strip_tags($this->body), 160);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(NewsReaction::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query, ?Carbon $on = null): Builder
    {
        $on ??= now();

        return $query
            ->where('is_published', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $on);
    }
}
