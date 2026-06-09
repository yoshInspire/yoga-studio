<?php

use App\Enums\SubscriptionType;

return [
    /** Срок действия абонемента в днях от даты начала (включительно) */
    'validity_days' => [
        'group' => 30,
        'individual' => 30,
    ],

    /**
     * Каталог онлайн-оплаты. Ключ — product_key в заказе.
     *
     * @var array<string, array{
     *     category: string,
     *     name: string,
     *     type: SubscriptionType,
     *     sessions: int,
     *     price: int,
     *     validity_days?: int,
     *     online: bool,
     * }>
     */
    'products' => [
        'group_trial' => [
            'category' => 'group',
            'name' => 'Пробное занятие',
            'type' => SubscriptionType::Group,
            'sessions' => 1,
            'price' => 1400,
            'online' => true,
        ],
        'group_single' => [
            'category' => 'group',
            'name' => 'Разовое занятие',
            'type' => SubscriptionType::Group,
            'sessions' => 1,
            'price' => 1600,
            'online' => true,
        ],
        'group_4' => [
            'category' => 'group',
            'name' => 'Абонемент · 4 занятия',
            'type' => SubscriptionType::Group,
            'sessions' => 4,
            'price' => 6000,
            'online' => true,
        ],
        'group_6' => [
            'category' => 'group',
            'name' => 'Абонемент · 6 занятий',
            'type' => SubscriptionType::Group,
            'sessions' => 6,
            'price' => 8400,
            'online' => true,
        ],
        'group_8' => [
            'category' => 'group',
            'name' => 'Абонемент · 8 занятий',
            'type' => SubscriptionType::Group,
            'sessions' => 8,
            'price' => 10400,
            'online' => true,
        ],
        'individual_single' => [
            'category' => 'individual',
            'name' => 'Разовое занятие',
            'type' => SubscriptionType::Individual,
            'sessions' => 1,
            'price' => 3500,
            'online' => true,
        ],
        'individual_4' => [
            'category' => 'individual',
            'name' => 'Абонемент · 4 занятия',
            'type' => SubscriptionType::Individual,
            'sessions' => 4,
            'price' => 13200,
            'online' => true,
        ],
        'individual_pair_single' => [
            'category' => 'individual',
            'name' => 'Парное занятие · разовое',
            'type' => SubscriptionType::Individual,
            'sessions' => 1,
            'price' => 6000,
            'online' => true,
        ],
    ],

    'categories' => [
        'group' => 'Групповые занятия',
        'individual' => 'Индивидуальные занятия',
    ],
];
