<?php

return [
    'group' => [
        'title' => 'Групповые занятия',
        'sections' => [
            [
                'items' => [
                    ['product_key' => 'group_trial', 'name' => 'Пробное занятие', 'highlight' => true],
                    ['product_key' => 'group_single', 'name' => 'Разовое занятие'],
                ],
            ],
            [
                'title' => 'Абонементы',
                'items' => [
                    ['product_key' => 'group_4', 'name' => '4 занятия'],
                    ['product_key' => 'group_6', 'name' => '6 занятий'],
                    ['product_key' => 'group_8', 'name' => '8 занятий'],
                ],
            ],
        ],
        'notes' => [
            'Длительность занятия — 60 минут',
            'Срок действия абонементов — 30 дней',
            'Группы до 6 человек · весь инвентарь в студии',
        ],
    ],
    'individual' => [
        'title' => 'Индивидуальные занятия',
        'sections' => [
            [
                'items' => [
                    ['product_key' => 'individual_single', 'name' => 'Разовое занятие'],
                    ['product_key' => 'individual_4', 'name' => 'Абонемент · 4 занятия'],
                ],
            ],
            [
                'title' => 'Парные (взрослые)',
                'items' => [
                    ['product_key' => 'individual_pair_single', 'name' => 'Разовое занятие'],
                ],
            ],
        ],
        'notes' => [
            'Длительность занятия — 60 минут',
            'Персональная программа под ваши цели',
        ],
    ],
];
