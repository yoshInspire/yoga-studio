<?php

return [
    'shop_id' => env('YOOKASSA_SHOP_ID'),
    'secret_key' => env('YOOKASSA_SECRET_KEY'),

    /** URL для HTTP-уведомлений — пропишите в личном кабинете ЮKassa */
    'webhook_url' => env('YOOKASSA_WEBHOOK_URL'),

    'currency' => 'RUB',
];
