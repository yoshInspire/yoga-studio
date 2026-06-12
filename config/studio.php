<?php

return [
    /** За сколько дней вперёд открыта запись */
    'booking_days_ahead' => 7,

    /** Максимум записей клиента в один день */
    'max_bookings_per_day' => 2,

    /** Отмена клиентом не позднее чем за N часов до начала (возврат на абонемент).
     * Базовое значение (используется как запасное, см. cancellation ниже). */
    'cancellation_deadline_hours' => 4,

    /**
     * Самостоятельная отмена записи клиентом.
     * Срок зависит от времени начала занятия:
     *  - занятия до 12:00 — отменить можно не позднее чем за `morning_hours` часов;
     *  - занятия с 12:00 — не позднее чем за `day_hours` часов.
     */
    'cancellation' => [
        'noon_hour' => 12,
        'morning_hours' => 14,
        'day_hours' => 4,
    ],

    /**
     * Автоотмена занятия при недоборе группы.
     * Если к контрольному времени на групповом занятии меньше `min_group_size`
     * подтверждённых записей — занятие отменяется, записи аннулируются с возвратом
     * на абонемент, клиентам и администратору уходят уведомления.
     * Контрольное время:
     *  - занятия до 12:00 — за `morning_hours` часов до начала;
     *  - занятия с 12:00 — за `day_hours` часов до начала.
     */
    'auto_cancel' => [
        'enabled' => true,
        'min_group_size' => 2,
        'noon_hour' => 12,
        'morning_hours' => 15,
        'day_hours' => 5,
    ],

    /** Напоминания по абонементам (почта/Telegram) */
    'reminders' => [
        /** Уведомить, когда осталось ровно столько занятий */
        'low_sessions_threshold' => 1,
        /** Уведомить, когда до окончания осталось столько дней или меньше */
        'expiring_days_threshold' => 5,
    ],

    /**
     * Информационные рассылки клиентам (почта/Telegram).
     */
    'mailings' => [
        'studio_address' => env('STUDIO_ADDRESS', 'Москва, ул. Академика Арцимовича, 13 (вход со двора)'),
        'daily_reminder' => [
            'enabled' => true,
            'time' => '20:00',
        ],
        'weekly_schedule' => [
            'enabled' => true,
            'time' => '14:00',
        ],
    ],

    /** Лимит мест в группе по умолчанию */
    'default_class_capacity' => 6,

    /** Длительность занятия по умолчанию (минуты) для сетки расписания */
    'default_class_duration_minutes' => [
        'group' => 90,
        'individual' => 60,
        'special_event' => 120,
        'default' => 90,
    ],

    /** Диапазон часов сетки расписания (если нет занятий вне этого окна) */
    'schedule_grid_hours' => [
        'start' => 6,
        'end' => 22,
    ],

    /** Максимальная длина темы/названия занятия */
    'class_title_max_length' => 120,

    /** Куда отправлять заявки с формы на главной */
    'lead_email' => env('STUDIO_LEAD_EMAIL', 'hello@ekoyoga-ik.ru'),

    /** Почта администратора для уведомлений (по умолчанию — ящик рассылки) */
    'admin_email' => env('STUDIO_ADMIN_EMAIL', env('MAIL_FROM_ADDRESS', 'ecoyoga-ik@yandex.ru')),

    /**
     * Письма администратору о действиях клиентов в личном кабинете
     * (регистрация, запись, отмена, оплата, профиль и т.д.).
     */
    'admin_activity_notifications' => [
        'enabled' => env('STUDIO_ADMIN_ACTIVITY_NOTIFICATIONS', true),
    ],

    /** Имя отправителя в письмах (поле «От кого» в почте) */
    'mail_from_name' => env('MAIL_FROM_NAME', 'ЭКО YOGA'),

    /** Код подтверждения email при регистрации (без Telegram) */
    'registration_email_verification_ttl_minutes' => 15,
    'registration_email_verification_max_attempts' => 5,

    /** Сброс пароля на сайте */
    'password_reset_ttl_minutes' => 15,
    'password_reset_max_attempts' => 5,

    /** Карточка организации на Яндекс.Картах (виджет отзывов) */
    'yandex_maps_org_id' => env('YANDEX_MAPS_ORG_ID', '175639395221'),
    'yandex_profile_url' => env('YANDEX_PROFILE_URL', 'https://yandex.ru/profile/175639395221'),
];
