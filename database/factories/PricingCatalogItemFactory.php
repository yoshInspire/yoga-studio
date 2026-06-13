<?php

namespace Database\Factories;

use App\Enums\PricingCategory;
use App\Enums\SubscriptionType;
use App\Models\PricingCatalogItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PricingCatalogItem> */
class PricingCatalogItemFactory extends Factory
{
    protected $model = PricingCatalogItem::class;

    public function definition(): array
    {
        return [
            'name' => 'Йога-нидра',
            'category' => PricingCategory::Group,
            'subscription_type' => SubscriptionType::SpecialEvent,
            'sessions' => 1,
            'price' => 2500,
            'validity_days' => 30,
            'online' => false,
            'active' => true,
            'section_title' => 'Доп. мероприятия',
            'highlight' => false,
            'sort_order' => 0,
        ];
    }
}
