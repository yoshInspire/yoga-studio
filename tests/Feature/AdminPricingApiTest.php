<?php

namespace Tests\Feature;

use App\Enums\PricingCategory;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\PricingCatalogItem;
use App\Models\ProductPrice;
use App\Models\User;
use App\Support\PurchaseCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Цены и тарифы из приложения (ADMIN_PLAN_2.md, фаза G).
 *
 * Главное здесь — что цена доезжает до онлайн-оплаты, а не только до витрины:
 * `PurchaseCatalog` держит цены в статическом кэше, и без сброса сайт
 * продолжил бы выставлять счета по старым числам.
 */
class AdminPricingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Кэш статический на процесс, а тесты идут один за другим.
        PurchaseCatalog::forgetPrices();
        PurchaseCatalog::forgetCustomProducts();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /** @return array<string, mixed> */
    private function itemPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Йога-нидра',
            'category' => PricingCategory::Group->value,
            'subscription_type' => SubscriptionType::SpecialEvent->value,
            'section_title' => 'Доп. мероприятия',
            'price' => 2500,
            'sessions' => 1,
            'validity_days' => 30,
            'sort_order' => 0,
            'active' => true,
            'online' => false,
            'highlight' => false,
        ], $overrides);
    }

    public function test_client_cannot_reach_pricing(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Client]), 'sanctum')
            ->getJson('/api/v1/admin/pricing')
            ->assertForbidden();
    }

    public function test_index_groups_base_tariffs_and_explains_the_type_rule(): void
    {
        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/pricing')
            ->assertOk()
            ->json();

        $categories = collect($payload['base'])->pluck('category')->all();
        $this->assertSame(['group', 'individual'], $categories);

        $group = collect($payload['base'])->firstWhere('category', 'group');
        $trial = collect($group['items'])->firstWhere('key', 'group_trial');

        $this->assertSame('Пробное занятие', $trial['name']);
        $this->assertSame(1400, $trial['price']);
        // Тариф продаётся раз в жизни — приложение это показывает.
        $this->assertTrue($trial['once_per_client']);

        // Правило «раздел ↔ тип» приходит с сервера, а не повторяется в коде.
        $this->assertSame(
            ['group' => ['group', 'special_event'], 'individual' => ['individual']],
            $payload['meta']['types_by_category'],
        );
    }

    public function test_saving_a_price_reports_what_changed_and_reaches_online_payment(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/pricing', ['prices' => ['group_4' => 6500]])
            ->assertOk();

        $response->assertJsonPath('changed.0.key', 'group_4');
        $response->assertJsonPath('changed.0.was', 6000);
        $response->assertJsonPath('changed.0.now', 6500);

        $this->assertDatabaseHas('product_prices', ['product_key' => 'group_4', 'price' => 6500]);

        // Кэш сброшен — счёт выставится по новой цене, а не по прежней.
        $this->assertSame(6500, PurchaseCatalog::price('group_4'));
        $this->assertSame(6500, PurchaseCatalog::find('group_4')['price']);
    }

    public function test_unchanged_price_is_not_reported_as_a_change(): void
    {
        // updateOrCreate, а не create: строка для этого ключа может уже быть.
        ProductPrice::query()->updateOrCreate(['product_key' => 'group_6'], ['price' => 8400]);
        PurchaseCatalog::forgetPrices();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/pricing', ['prices' => ['group_6' => 8400]])
            ->assertOk()
            ->assertJsonPath('changed', [])
            ->assertJsonPath('message', 'Цены не изменились.');
    }

    public function test_unknown_product_key_is_ignored(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/pricing', ['prices' => ['no_such_product' => 100]])
            ->assertOk()
            ->assertJsonPath('changed', []);

        $this->assertDatabaseMissing('product_prices', ['product_key' => 'no_such_product']);
    }

    public function test_price_below_one_is_rejected(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/pricing', ['prices' => ['group_4' => 0]])
            ->assertStatus(422);

        // Ключ ошибки — «prices.group_4» целиком, точку в нём нельзя читать
        // как разделитель пути, поэтому берём массив ошибок как есть.
        $this->assertSame(
            ['Цена должна быть больше нуля.'],
            $response->json('errors')['prices.group_4'] ?? null,
        );
    }

    public function test_extra_tariff_is_created_with_a_generated_key(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/pricing/items', $this->itemPayload())
            ->assertCreated();

        $response->assertJsonPath('data.name', 'Йога-нидра');
        $response->assertJsonPath('data.type_label', SubscriptionType::SpecialEvent->shortLabel());
        $this->assertStringStartsWith('extra_', (string) $response->json('data.product_key'));
    }

    public function test_type_must_match_the_pricing_section(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/pricing/items', $this->itemPayload([
                'category' => PricingCategory::Individual->value,
                // Групповой тип в индивидуальном разделе: списание бы не сошлось,
                // isCompatibleWith() — строгое равенство.
                'subscription_type' => SubscriptionType::Group->value,
            ]))
            ->assertStatus(422);
    }

    public function test_extra_tariff_can_be_edited_and_deleted(): void
    {
        $item = PricingCatalogItem::create($this->itemPayload());

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/pricing/items/'.$item->id, $this->itemPayload([
                'name' => 'Йога-нидра · вечерняя',
                'price' => 2800,
                'active' => false,
            ]))
            ->assertOk()
            ->assertJsonPath('data.price', 2800)
            ->assertJsonPath('data.active', false);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/pricing/items/'.$item->id)
            ->assertOk();

        $this->assertDatabaseMissing('pricing_catalog_items', ['id' => $item->id]);
    }

    public function test_online_extra_tariff_becomes_purchasable(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/pricing/items', $this->itemPayload([
                'name' => 'Мастер-класс',
                'online' => true,
                'price' => 3000,
            ]))
            ->assertCreated();

        PurchaseCatalog::forgetCustomProducts();
        $key = PricingCatalogItem::first()->product_key;

        $this->assertSame(3000, PurchaseCatalog::find($key)['price']);
    }

    public function test_move_swaps_neighbours(): void
    {
        $first = PricingCatalogItem::create($this->itemPayload(['name' => 'Первый', 'sort_order' => 1]));
        $second = PricingCatalogItem::create($this->itemPayload(['name' => 'Второй', 'sort_order' => 2]));

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/pricing/items/'.$second->id.'/move', ['direction' => 'up'])
            ->assertOk();

        $this->assertSame(['Второй', 'Первый'], collect($response->json('items'))->pluck('name')->all());
    }

    public function test_move_beyond_the_edge_is_refused(): void
    {
        $only = PricingCatalogItem::create($this->itemPayload(['sort_order' => 0]));

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/pricing/items/'.$only->id.'/move', ['direction' => 'up'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Двигать некуда.');
    }
}
