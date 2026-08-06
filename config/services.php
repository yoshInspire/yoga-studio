<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'auth_max_age' => (int) env('TELEGRAM_AUTH_MAX_AGE', 86400),
        /** Обход блокировки api.telegram.org на VPS: host:port:ip */
        'curl_resolve' => env('TELEGRAM_CURL_RESOLVE', 'api.telegram.org:443:149.154.167.220'),
    ],

    /**
     * Пуш-уведомления в мобильное приложение.
     * `expo` — Expo Push Service (текущее приложение на React Native),
     * `null` — не отправлять (тесты, аварийное выключение без выката кода).
     * После переезда на Flutter здесь появится `fcm`.
     */
    'push' => [
        'driver' => env('PUSH_DRIVER', 'expo'),
    ],

];
