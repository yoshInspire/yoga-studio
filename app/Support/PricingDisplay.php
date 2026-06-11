<?php

namespace App\Support;

class PricingDisplay
{
    /**
     * @return array<string, array{
     *     title: string,
     *     sections: list<array{
     *         title?: string,
     *         items: list<array{
     *             name: string,
     *             price: int,
     *             highlight?: bool,
     *         }>,
     *     }>,
     *     notes: list<string>,
     * }>
     */
    public static function blocks(): array
    {
        $blocks = config('pricing', []);

        foreach ($blocks as &$block) {
            foreach ($block['sections'] as &$section) {
                foreach ($section['items'] as &$item) {
                    if (! empty($item['product_key'])) {
                        $item['price'] = PurchaseCatalog::price((string) $item['product_key']);
                    }
                }
            }
        }

        return $blocks;
    }
}
