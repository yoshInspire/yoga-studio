<?php

return [
    'shop_id' => env('YOOKASSA_SHOP_ID'),
    'secret_key' => env('YOOKASSA_SECRET_KEY'),

    /** URL для HTTP-уведомлений — пропишите в личном кабинете ЮKassa */
    'webhook_url' => env('YOOKASSA_WEBHOOK_URL'),

    'currency' => 'RUB',

    /** 1 = без НДС (обычно для УСН). См. https://yookassa.ru/developers/54fz/parameters-values#vat-codes */
    'vat_code' => env('YOOKASSA_VAT_CODE', 1),

    /** 2 = УСН «доходы». См. https://yookassa.ru/developers/54fz/parameters-values#tax-systems */
    'tax_system_code' => env('YOOKASSA_TAX_SYSTEM_CODE', 2),

    /**
     * Способ «СБП — любой банк» на странице покупки работает только если СБП
     * подключён в личном кабинете ЮKassa (отдельно от SberPay и T-Pay).
     */
];
