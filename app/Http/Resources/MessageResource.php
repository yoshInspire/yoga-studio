<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Models\User;
use App\Support\RussianDate;

/**
 * Сообщение в том виде, в каком его ждёт приложение.
 *
 * `mine` считается относительно смотрящего: одно и то же сообщение для клиента
 * и для администратора оказывается по разные стороны ленты.
 */
class MessageResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Message $message, User $viewer): array
    {
        $created = $message->created_at;

        return [
            'id' => $message->id,
            'body' => $message->body,
            'mine' => $message->sender_id === $viewer->id,
            'from_client' => $message->isFromClient(),
            'author' => $message->sender?->shortName(),
            'photo' => $message->hasAttachment() ? route('api.chat.attachment', $message) : null,
            'photo_width' => $message->attachment_width,
            'photo_height' => $message->attachment_height,
            'read' => $message->read_at !== null,
            'created_at' => $created?->toIso8601String(),
            'time' => $created?->format('H:i'),
            'day' => $created ? RussianDate::dayMonth($created) : null,
            'day_key' => $created?->toDateString(),
        ];
    }

    /**
     * @param  iterable<Message>  $messages
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $messages, User $viewer): array
    {
        $out = [];

        foreach ($messages as $message) {
            $out[] = self::make($message, $viewer);
        }

        return $out;
    }
}
