<?php

namespace App\Support;

use App\Enums\SubscriptionType;
use InvalidArgumentException;

class PurchaseCatalog
{
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
        return collect(config('purchases.products', []))
            ->filter(fn (array $product) => ($product['online'] ?? false) === true)
            ->map(fn (array $product, string $key) => self::normalize($key, $product))
            ->all();
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
        $product = config("purchases.products.{$key}");

        if (! is_array($product) || ($product['online'] ?? false) !== true) {
            throw new InvalidArgumentException('Выбранный тариф недоступен для онлайн-оплаты.');
        }

        return self::normalize($key, $product);
    }

    public static function categoryLabel(string $category): string
    {
        return config("purchases.categories.{$category}", $category);
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
            'price' => (int) $product['price'],
            'validity_days' => $validityDays,
            'online' => (bool) ($product['online'] ?? false),
        ];
    }
}
