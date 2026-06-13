<?php

namespace App\Support;

use App\Models\PricingCatalogItem;

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
        unset($block, $section, $item);

        foreach (PurchaseCatalog::activeCatalogItems() as $catalogItem) {
            $category = $catalogItem->category->value;

            if (! isset($blocks[$category])) {
                continue;
            }

            self::appendCatalogItem($blocks[$category], $catalogItem);
        }

        return $blocks;
    }

    /**
     * @param  array{
     *     title: string,
     *     sections: list<array{
     *         title?: string,
     *         items: list<array{
     *             name: string,
     *             price?: int,
     *             highlight?: bool,
     *             product_key?: string,
     *         }>,
     *     }>,
     *     notes: list<string>,
     * }  $block
     */
    private static function appendCatalogItem(array &$block, PricingCatalogItem $item): void
    {
        $entry = [
            'name' => $item->name,
            'price' => $item->price,
            'highlight' => $item->highlight,
        ];

        if ($item->section_title) {
            foreach ($block['sections'] as &$section) {
                if (($section['title'] ?? null) === $item->section_title) {
                    $section['items'][] = $entry;

                    return;
                }
            }

            $block['sections'][] = [
                'title' => $item->section_title,
                'items' => [$entry],
            ];

            return;
        }

        $block['sections'][0]['items'][] = $entry;
    }
}
