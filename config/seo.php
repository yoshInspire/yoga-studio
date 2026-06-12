<?php

return [
    'site_name' => 'Студия йоги Ирины Коленцевой',
    'site_name_short' => 'ЭКО YOGA',

    'default_title' => 'Студия йоги Ирины Коленцевой — Москва, Коньково',
    'default_description' => 'Студия йоги Ирины Коленцевой в Москве (р-н Коньково): хатха, виньяса, йогатерапия, группы до 6 человек. Расписание, абонементы, запись онлайн.',

    /** Путь от public/ для Open Graph, если у страницы нет своего изображения */
    'default_og_image' => 'images/studio/hero-hall.webp',

    'locale' => 'ru_RU',

    'local_business' => [
        '@type' => 'YogaStudio',
        'name' => 'Студия йоги Ирины Коленцевой',
        'alternateName' => 'ЭКО YOGA',
        'description' => 'Камерная студия йоги в районе Коньково: группы до 6 человек, индивидуальный подход, 17 направлений практики.',
        'telephone' => '+7-964-783-43-53',
        'email' => env('STUDIO_LEAD_EMAIL', 'hello@ekoyoga-ik.ru'),
        'url' => env('APP_URL', 'https://ekoyoga-ik.ru'),
        'priceRange' => '₽₽',
        'address' => [
            'streetAddress' => 'ул. Академика Арцимовича, 13',
            'addressLocality' => 'Москва',
            'addressRegion' => 'Москва',
            'postalCode' => '117647',
            'addressCountry' => 'RU',
        ],
        'geo' => [
            'latitude' => 55.6337,
            'longitude' => 37.5199,
        ],
        'openingHours' => ['Mo-Su 07:00-22:00'],
        'sameAs' => array_values(array_filter([
            env('YANDEX_PROFILE_URL', 'https://yandex.ru/profile/175639395221'),
            'https://t.me/yogAvLife',
        ])),
    ],
];
