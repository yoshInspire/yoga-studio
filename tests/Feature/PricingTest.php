<?php

namespace Tests\Feature;

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
}
