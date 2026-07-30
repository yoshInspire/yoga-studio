<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'category',
    'image_path',
    'is_custom',
    'sort',
])]
class Asana extends Model
{
    /** Категория, под которой собираются собственные зарисовки. */
    public const CUSTOM_CATEGORY = 'Мои зарисовки';

    protected function casts(): array
    {
        return [
            'is_custom' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * Ссылка от корня сайта, а не от APP_URL: рисовалка читает картинку в canvas,
     * и любой другой origin «испортил» бы холст — toDataURL перестал бы работать.
     */
    public function imageUrl(): string
    {
        return '/'.ltrim($this->image_path, '/');
    }

    public function categoryLabel(): string
    {
        return $this->is_custom
            ? self::CUSTOM_CATEGORY
            : ($this->category ?: 'Без категории');
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
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where('name', 'like', '%'.trim($term).'%');
    }
}
