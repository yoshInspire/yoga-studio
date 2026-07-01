<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\RussianDate;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable([
    'title',
    'slug',
    'excerpt',
    'body',
    'image_path',
    'is_published',
    'published_at',
    'notifications_sent_at',
])]
class News extends Model
{
    protected $table = 'news';

    protected static function booted(): void
    {
        static::saving(function (News $news): void {
            if (blank($news->slug) && filled($news->title)) {
                $news->slug = Seo::uniqueSlug($news->title, 'news', $news->exists ? $news->id : null);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        if (ctype_digit((string) $value)) {
            return static::query()->whereKey($value)->first();
        }

        return static::query()->where('slug', $value)->first();
    }

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'notifications_sent_at' => 'datetime',
        ];
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function formattedDate(): string
    {
        return RussianDate::dayMonthYear($this->published_at ?? $this->created_at);
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
