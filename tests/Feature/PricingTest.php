<?php

namespace Tests\Feature;

use App\Enums\PricingCategory;
use App\Enums\SubscriptionType;
use App\Models\PricingCatalogItem;
use App\Models\ProductPrice;
use App\Support\PricingDisplay;
use App\Support\PurchaseCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pricing_blocks_use_database_prices(): void
    {
        ProductPrice::query()->where('product_key', 'group_4')->update(['price' => 6500]);
        PurchaseCatalog::forgetPrices();

        $blocks = PricingDisplay::blocks();

        $this->assertSame(6500, $blocks['group']['sections'][1]['items'][0]['price']);
        $this->assertSame(6500, PurchaseCatalog::onlineProducts()['group_4']['price']);
    }

    public function test_home_page_shows_updated_price(): void
    {
        ProductPrice::query()->where('product_key', 'group_trial')->update(['price' => 1500]);
        PurchaseCatalog::forgetPrices();

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('1 500 ₽');
    }

    public function test_purchase_page_shows_updated_price(): void
    {
        $user = \App\Models\User::create([
            'first_name' => 'Анна',
            'last_name' => 'Смирнова',
            'phone' => '+79991112239',
            'role' => \App\Enums\UserRole::Client,
            'password' => 'secret123',
        ]);

        ProductPrice::query()->where('product_key', 'group_4')->update(['price' => 6200]);
        PurchaseCatalog::forgetPrices();

        $this->actingAs($user)
            ->get(route('purchase.index'))
            ->assertOk()
            ->assertSee('6 200 ₽');
    }

    public function test_custom_catalog_item_appears_in_group_pricing_block(): void
    {
        PricingCatalogItem::factory()->create([
            'name' => 'Йога-нидра',
            'category' => PricingCategory::Group,
            'subscription_type' => SubscriptionType::SpecialEvent,
            'price' => 2800,
            'section_title' => 'Доп. мероприятия',
        ]);
        PurchaseCatalog::forgetCustomProducts();

        $blocks = PricingDisplay::blocks();
        $section = collect($blocks['group']['sections'])
            ->first(fn (array $section): bool => ($section['title'] ?? null) === 'Доп. мероприятия');

        $this->assertNotNull($section);
        $this->assertSame('Йога-нидра', $section['items'][0]['name']);
        $this->assertSame(2800, $section['items'][0]['price']);
    }

    public function test_custom_catalog_item_price_update_is_reflected_on_home_page(): void
    {
        $item = PricingCatalogItem::factory()->create([
            'name' => 'Стояние на гвоздях',
            'category' => PricingCategory::Individual,
            'subscription_type' => SubscriptionType::Individual,
            'price' => 4000,
        ]);
        PurchaseCatalog::forgetCustomProducts();

        $item->update(['price' => 4500]);
        PurchaseCatalog::forgetCustomProducts();

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('4 500 ₽')
            ->assertSee('Стояние на гвоздях');
    }

    public function test_inactive_custom_catalog_item_is_hidden_from_pricing_block(): void
    {
        PricingCatalogItem::factory()->create([
            'name' => 'Закрытое мероприятие',
            'active' => false,
        ]);
        PurchaseCatalog::forgetCustomProducts();

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Закрытое мероприятие');
    }

    public function test_online_custom_catalog_item_appears_on_purchase_page(): void
    {
        $user = \App\Models\User::create([
            'first_name' => 'Анна',
            'last_name' => 'Смирнова',
            'phone' => '+79991112240',
            'role' => \App\Enums\UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);

        PricingCatalogItem::factory()->create([
            'name' => 'Йога-нидра онлайн',
            'category' => PricingCategory::Group,
            'subscription_type' => SubscriptionType::SpecialEvent,
            'price' => 2900,
            'online' => true,
        ]);
        PurchaseCatalog::forgetCustomProducts();

        $this->actingAs($user)
            ->get(route('purchase.index'))
            ->assertOk()
            ->assertSee('Йога-нидра онлайн')
            ->assertSee('2 900 ₽');
    }
}
