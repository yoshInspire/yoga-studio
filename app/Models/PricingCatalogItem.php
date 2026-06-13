<?php

namespace App\Models;

use App\Enums\PricingCategory;
use App\Enums\SubscriptionType;
use App\Support\PurchaseCatalog;
use Database\Factories\PricingCatalogItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable([
    'product_key',
    'name',
    'category',
    'subscription_type',
    'sessions',
    'price',
    'validity_days',
    'online',
    'active',
    'section_title',
    'highlight',
    'sort_order',
])]
class PricingCatalogItem extends Model
{
    /** @use HasFactory<PricingCatalogItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => PricingCategory::class,
            'subscription_type' => SubscriptionType::class,
            'sessions' => 'integer',
            'price' => 'integer',
            'validity_days' => 'integer',
            'online' => 'boolean',
            'active' => 'boolean',
            'highlight' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PricingCatalogItem $item): void {
            if (blank($item->product_key)) {
                $item->product_key = self::generateProductKey($item->name);
            }

            if ($item->validity_days === null) {
                $item->validity_days = (int) config(
                    'purchases.validity_days.'.$item->category->value,
                    30,
                );
            }
        });

        static::saved(fn () => PurchaseCatalog::forgetCustomProducts());
        static::deleted(fn () => PurchaseCatalog::forgetCustomProducts());
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public static function generateProductKey(string $name): string
    {
        $base = 'extra_'.Str::slug($name, '_');

        if ($base === 'extra_') {
            $base = 'extra_item';
        }

        $key = $base;
        $suffix = 2;

        while (static::query()->where('product_key', $key)->exists()
            || config("purchases.products.{$key}") !== null) {
            $key = $base.'_'.$suffix;
            $suffix++;
        }

        return $key;
    }
}
