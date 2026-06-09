<?php

return [
    /** За сколько дней вперёд открыта запись */
    'booking_days_ahead' => 7,

    /** Максимум записей клиента в один день */
    'max_bookings_per_day' => 2,

    /** Отмена клиентом не позднее чем за N часов до начала (возврат на абонемент) */
    'cancellation_deadline_hours' => 4,

    /** Лимит мест в группе по умолчанию */
    'default_class_capacity' => 6,

    /** Куда отправлять заявки с формы на главной */
    'lead_email' => env('STUDIO_LEAD_EMAIL', 'hello@ekoyoga-ik.ru'),

    /** Имя отправителя в письмах (поле «От кого» в почте) */
    'mail_from_name' => env('MAIL_FROM_NAME', 'ЭКО YOGA'),

    /** Код подтверждения email при регистрации (без Telegram) */
    'registration_email_verification_ttl_minutes' => 15,
    'registration_email_verification_max_attempts' => 5,

    /** Карточка организации на Яндекс.Картах (виджет отзывов) */
    'yandex_maps_org_id' => env('YANDEX_MAPS_ORG_ID', '175639395221'),
    'yandex_profile_url' => env('YANDEX_PROFILE_URL', 'https://yandex.ru/profile/175639395221'),
];
