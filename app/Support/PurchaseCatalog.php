<?php

namespace App\Support;

use App\Enums\SubscriptionType;
use App\Models\PricingCatalogItem;
use App\Models\ProductPrice;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PurchaseCatalog
{
    /** @var array<string, int>|null */
    private static ?array $cachedPrices = null;

    /** @var Collection<int, PricingCatalogItem>|null */
    private static ?Collection $cachedCustomProducts = null;

    /**
     * @return array<string, array{
     *     category: string,
     *     name: string,
     *     type: SubscriptionType,
     *     sessions: int,
     *     price: int,
     *     validity_days: int,
     *     online: bool,
     * }>
     */
    public static function onlineProducts(): array
    {
        $products = collect(config('purchases.products', []))
            ->filter(fn (array $product) => ($product['online'] ?? false) === true)
            ->map(fn (array $product, string $key) => self::normalize($key, $product));

        foreach (self::customProducts() as $item) {
            if (! $item->online || ! $item->active) {
                continue;
            }

            $products->put($item->product_key, self::normalizeCustom($item));
        }

        return $products->all();
    }

    /**
     * @return array<string, list<array{
     *     key: string,
     *     category: string,
     *     name: string,
     *     type: SubscriptionType,
     *     sessions: int,
     *     price: int,
     *     validity_days: int,
     *     online: bool,
     * }>>
     */
    public static function groupedOnlineProducts(): array
    {
        $grouped = [];

        foreach (self::onlineProducts() as $key => $product) {
            $product['key'] = $key;
            $grouped[$product['category']][] = $product;
        }

        return $grouped;
    }

    /**
     * @return array{
     *     category: string,
     *     name: string,
     *     type: SubscriptionType,
     *     sessions: int,
     *     price: int,
     *     validity_days: int,
     *     online: bool,
     * }
     */
    public static function find(string $key): array
    {
        $custom = self::customProduct($key);

        if ($custom !== null) {
            if (! $custom->online || ! $custom->active) {
                throw new InvalidArgumentException('Выбранный тариф недоступен для онлайн-оплаты.');
            }

            return self::normalizeCustom($custom);
        }

        $product = config("purchases.products.{$key}");

        if (! is_array($product) || ($product['online'] ?? false) !== true) {
            throw new InvalidArgumentException('Выбранный тариф недоступен для онлайн-оплаты.');
        }

        return self::normalize($key, $product);
    }

    public static function price(string $key): int
    {
        $custom = self::customProduct($key);

        if ($custom !== null) {
            return $custom->price;
        }

        $default = (int) config("purchases.products.{$key}.price", 0);

        return self::prices()[$key] ?? $default;
    }

    public static function forgetPrices(): void
    {
        self::$cachedPrices = null;
    }

    public static function forgetCustomProducts(): void
    {
        self::$cachedCustomProducts = null;
    }

    public static function categoryLabel(string $category): string
    {
        return config("purchases.categories.{$category}", $category);
    }

    /**
     * @return Collection<int, PricingCatalogItem>
     */
    public static function activeCatalogItems(): Collection
    {
        return self::customProducts()
            ->filter(fn (PricingCatalogItem $item): bool => $item->active)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{
     *     category: string,
     *     name: string,
     *     type: SubscriptionType,
     *     sessions: int,
     *     price: int,
     *     validity_days: int,
     *     online: bool,
     * }
     */
    private static function normalize(string $key, array $product): array
    {
        $category = (string) ($product['category'] ?? 'group');
        $validityDays = (int) ($product['validity_days']
            ?? config("purchases.validity_days.{$category}", 30));

        return [
            'category' => $category,
            'name' => (string) $product['name'],
            'type' => $product['type'] instanceof SubscriptionType
                ? $product['type']
                : SubscriptionType::from((string) $product['type']),
            'sessions' => (int) $product['sessions'],
            'price' => self::price($key),
            'validity_days' => $validityDays,
            'online' => (bool) ($product['online'] ?? false),
        ];
    }

    /**
     * @return array{
     *     category: string,
     *     name: string,
     *     type: SubscriptionType,
     *     sessions: int,
     *     price: int,
     *     validity_days: int,
     *     online: bool,
     * }
     */
    private static function normalizeCustom(PricingCatalogItem $item): array
    {
        return [
            'category' => $item->category->value,
            'name' => $item->name,
            'type' => $item->subscription_type,
            'sessions' => $item->sessions,
            'price' => $item->price,
            'validity_days' => $item->validity_days
                ?? (int) config('purchases.validity_days.'.$item->category->value, 30),
            'online' => $item->online,
        ];
    }

    private static function customProduct(string $key): ?PricingCatalogItem
    {
        return self::customProducts()->firstWhere('product_key', $key);
    }

    /**
     * @return Collection<int, PricingCatalogItem>
     */
    private static function customProducts(): Collection
    {
        if (self::$cachedCustomProducts !== null) {
            return self::$cachedCustomProducts;
        }

        if (! self::catalogTableExists()) {
            self::$cachedCustomProducts = collect();

            return self::$cachedCustomProducts;
        }

        self::$cachedCustomProducts = PricingCatalogItem::query()->ordered()->get();

        return self::$cachedCustomProducts;
    }

    /**
     * @return array<string, int>
     */
    private static function prices(): array
    {
        if (self::$cachedPrices !== null) {
            return self::$cachedPrices;
        }

        if (! self::pricesTableExists()) {
            self::$cachedPrices = [];

            return self::$cachedPrices;
        }

        self::$cachedPrices = ProductPrice::query()
            ->pluck('price', 'product_key')
            ->map(fn (mixed $price): int => (int) $price)
            ->all();

        return self::$cachedPrices;
    }

    private static function pricesTableExists(): bool
    {
        try {
            return ProductPrice::query()->getConnection()->getSchemaBuilder()->hasTable('product_prices');
        } catch (\Throwable) {
            return false;
        }
    }

    private static function catalogTableExists(): bool
    {
        try {
            return PricingCatalogItem::query()->getConnection()->getSchemaBuilder()->hasTable('pricing_catalog_items');
        } catch (\Throwable) {
            return false;
        }
    }
}
