<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PricingCategory;
use App\Enums\SubscriptionType;
use App\Http\Controllers\Controller;
use App\Models\PricingCatalogItem;
use App\Models\ProductPrice;
use App\Support\PurchaseCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Цены и тарифы из приложения.
 *
 * Прайс собирается из двух источников, и это видно в ответе:
 *
 * - **базовые тарифы** — ключи из `config/purchases.php`, цена каждого лежит в
 *   таблице `product_prices` и правится здесь. Состав (название, число занятий)
 *   в конфиге и с телефона не меняется: это не цена, а устройство каталога;
 * - **доп. тарифы** — строки `pricing_catalog_items`, полноценный CRUD.
 *
 * **Цена уходит не только в витрину, но и в онлайн-оплату**, поэтому ответ на
 * сохранение перечисляет, что именно изменилось: «было → стало». После записи
 * обязателен сброс кэша `PurchaseCatalog::forgetPrices()` — иначе сайт
 * продолжит показывать старую цену до перезапуска процесса.
 *
 * Правило «раздел прайса ↔ тип абонемента» живёт здесь и отдаётся в `meta`.
 * В веб-админке оно зашито в форму Filament; повторять его ещё и в приложении
 * значило бы завести второй источник правды.
 */
class PricingController extends Controller
{
    /** Какие типы абонемента допустимы в каждом разделе прайса. */
    private const TYPES_BY_CATEGORY = [
        'group' => [SubscriptionType::Group, SubscriptionType::SpecialEvent],
        'individual' => [SubscriptionType::Individual],
    ];

    /** Базовые тарифы, доп. тарифы и справочники для формы. */
    public function index(): JsonResponse
    {
        return response()->json([
            'base' => $this->baseGroups(),
            'items' => PricingCatalogItem::query()->ordered()->get()
                ->map(fn (PricingCatalogItem $i) => $this->item($i))->all(),
            'meta' => [
                'categories' => collect(PricingCategory::cases())->map(fn (PricingCategory $c) => [
                    'value' => $c->value,
                    'label' => $c->label(),
                ])->all(),
                'types' => collect(SubscriptionType::cases())->map(fn (SubscriptionType $t) => [
                    'value' => $t->value,
                    'label' => $t->shortLabel(),
                ])->all(),
                // Приложение гасит недопустимые варианты по этой таблице.
                'types_by_category' => collect(self::TYPES_BY_CATEGORY)
                    ->map(fn (array $types) => collect($types)->map(fn (SubscriptionType $t) => $t->value)->all())
                    ->all(),
                'default_validity_days' => (array) config('purchases.validity_days', []),
            ],
        ]);
    }

    /**
     * Сохранить цены базовых тарифов.
     *
     * Принимаем только знакомые ключи: чужой ключ означал бы строку в
     * `product_prices`, на которую ничто не смотрит.
     */
    public function updateBase(Request $request): JsonResponse
    {
        $known = array_keys((array) config('purchases.products', []));

        $data = $request->validate([
            'prices' => ['required', 'array', 'min:1'],
            'prices.*' => ['required', 'integer', 'min:1', 'max:999999'],
        ], [
            'prices.required' => 'Нечего сохранять.',
            'prices.*.min' => 'Цена должна быть больше нуля.',
        ]);

        $changes = [];

        DB::transaction(function () use ($data, $known, &$changes): void {
            foreach ($data['prices'] as $key => $price) {
                if (! in_array($key, $known, true)) {
                    continue;
                }

                $was = PurchaseCatalog::price((string) $key);

                if ($was === (int) $price) {
                    continue;
                }

                ProductPrice::query()->updateOrCreate(
                    ['product_key' => (string) $key],
                    ['price' => (int) $price],
                );

                $changes[] = [
                    'key' => (string) $key,
                    'name' => (string) config("purchases.products.{$key}.name", $key),
                    'was' => $was,
                    'now' => (int) $price,
                ];
            }
        });

        // Кэш цен статический на процесс: без сброса сайт продолжит отдавать
        // прежние числа и в витрине, и при выставлении счёта.
        PurchaseCatalog::forgetPrices();

        return response()->json([
            'changed' => $changes,
            'base' => $this->baseGroups(),
            'message' => $changes === []
                ? 'Цены не изменились.'
                : 'Новые цены действуют на сайте и при онлайн-оплате.',
        ]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $item = PricingCatalogItem::create($this->validatedItem($request));

        return response()->json([
            'data' => $this->item($item),
            'message' => 'Тариф добавлен.',
        ], 201);
    }

    public function updateItem(Request $request, PricingCatalogItem $item): JsonResponse
    {
        $item->update($this->validatedItem($request));

        return response()->json([
            'data' => $this->item($item->refresh()),
            'message' => 'Тариф сохранён.',
        ]);
    }

    public function destroyItem(PricingCatalogItem $item): JsonResponse
    {
        $item->delete();

        return response()->json(['message' => 'Тариф удалён.']);
    }

    /**
     * Переставить тариф выше или ниже соседа.
     *
     * Перетаскивание из веб-админки на телефон не переносится (ADMIN_PLAN_2.md
     * §7.2): в списке RN это дорого и капризно, а позиций в прайсе десяток.
     * Меняемся `sort_order` с соседом — порядок остаётся плотным.
     */
    public function moveItem(Request $request, PricingCatalogItem $item): JsonResponse
    {
        $direction = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
        ])['direction'];

        $neighbour = PricingCatalogItem::query()
            ->when(
                $direction === 'up',
                fn ($q) => $q->where('sort_order', '<=', $item->sort_order)
                    ->where('id', '!=', $item->id)
                    ->orderByDesc('sort_order')->orderByDesc('id'),
                fn ($q) => $q->where('sort_order', '>=', $item->sort_order)
                    ->where('id', '!=', $item->id)
                    ->orderBy('sort_order')->orderBy('id'),
            )
            ->first();

        if ($neighbour === null) {
            return response()->json(['message' => 'Двигать некуда.'], 422);
        }

        DB::transaction(function () use ($item, $neighbour): void {
            $mine = $item->sort_order;
            $theirs = $neighbour->sort_order;

            // Порядок мог совпадать (обе позиции с 0) — тогда одного разводим
            // на шаг, иначе обмен ничего не изменит.
            if ($mine === $theirs) {
                $theirs = $mine + 1;
            }

            $item->update(['sort_order' => $theirs]);
            $neighbour->update(['sort_order' => $mine]);
        });

        return response()->json([
            'items' => PricingCatalogItem::query()->ordered()->get()
                ->map(fn (PricingCatalogItem $i) => $this->item($i))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedItem(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(PricingCategory::class)],
            'subscription_type' => ['required', Rule::enum(SubscriptionType::class)],
            'section_title' => ['nullable', 'string', 'max:120'],
            'price' => ['required', 'integer', 'min:1', 'max:999999'],
            'sessions' => ['required', 'integer', 'min:1', 'max:100'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'active' => ['required', 'boolean'],
            'online' => ['required', 'boolean'],
            'highlight' => ['required', 'boolean'],
        ], [
            'name.required' => 'Назовите тариф.',
            'price.required' => 'Укажите цену.',
            'sessions.required' => 'Укажите число занятий.',
        ]);

        $allowed = self::TYPES_BY_CATEGORY[$data['category']] ?? [];

        // Индивидуальный тариф в групповом разделе (и наоборот) сломал бы
        // списание: `SubscriptionType::isCompatibleWith()` — строгое равенство.
        if (! in_array(SubscriptionType::from($data['subscription_type']), $allowed, true)) {
            abort(422, 'Этот тип абонемента не подходит выбранному разделу прайса.');
        }

        $data['sort_order'] ??= 0;

        return $data;
    }

    /**
     * Базовые тарифы, разложенные по разделам прайса.
     *
     * @return list<array<string, mixed>>
     */
    private function baseGroups(): array
    {
        $groups = [];

        foreach ((array) config('purchases.categories', []) as $category => $label) {
            $items = [];

            foreach ((array) config('purchases.products', []) as $key => $product) {
                if (($product['category'] ?? null) !== $category) {
                    continue;
                }

                $items[] = [
                    'key' => (string) $key,
                    'name' => (string) $product['name'],
                    'sessions' => (int) $product['sessions'],
                    'price' => PurchaseCatalog::price((string) $key),
                    // Цена из конфига — к ней можно вернуться, если ошиблись.
                    'default_price' => (int) ($product['price'] ?? 0),
                    'online' => (bool) ($product['online'] ?? false),
                    'once_per_client' => PurchaseCatalog::isOncePerClient((string) $key),
                ];
            }

            if ($items !== []) {
                $groups[] = ['category' => $category, 'label' => (string) $label, 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(PricingCatalogItem $item): array
    {
        return [
            'id' => $item->id,
            'product_key' => $item->product_key,
            'name' => $item->name,
            'category' => $item->category->value,
            'category_label' => $item->category->label(),
            'subscription_type' => $item->subscription_type->value,
            'type_label' => $item->subscription_type->shortLabel(),
            'section_title' => $item->section_title,
            'price' => $item->price,
            'sessions' => $item->sessions,
            'validity_days' => $item->validity_days,
            'sort_order' => $item->sort_order,
            'active' => $item->active,
            'online' => $item->online,
            'highlight' => $item->highlight,
        ];
    }
}
