<?php

namespace App\Support\Push;

/**
 * Отправка пуш-уведомлений на устройства.
 *
 * Интерфейс существует ради переезда на Flutter: сегодня приложение собрано на
 * Expo и токены выглядят как `ExponentPushToken[...]`, после переезда приедут
 * токены FCM и APNs, и поменяться должна ровно одна реализация. Всё остальное —
 * таблицы `push_tokens` и `client_notifications`, API, места вызова в
 * NotificationService — переезд переживают без правок.
 *
 * Реализация обязана быть «мягкой»: пуш — не критичный канал, его провал не
 * должен ронять отмену занятия или сохранение новости.
 */
interface PushSender
{
    /**
     * Отправить одно сообщение на несколько устройств.
     *
     * @param  list<string>  $tokens  токены только своего провайдера
     * @param  array<string, mixed>  $data  полезная нагрузка для перехода по тапу
     * @return int сколько сообщений сервис принял
     */
    public function send(array $tokens, string $title, string $body, array $data = []): int;

    /** Имя провайдера, чьи токены умеет отправлять: expo, fcm, apns. */
    public function provider(): string;
}
