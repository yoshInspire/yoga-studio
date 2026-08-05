<?php

namespace App\Models;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Сообщение в переписке. Либо текст, либо фото, либо и то и другое.
 */
#[Fillable([
    'conversation_id',
    'sender_id',
    'body',
    'attachment_path',
    'attachment_width',
    'attachment_height',
    'read_at',
])]
class Message extends Model
{
    /** Вложения лежат в приватном диске — прямой ссылки на них нет. */
    public const DISK = 'local';

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'attachment_width' => 'integer',
            'attachment_height' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** Написано клиентом (а не студией). */
    public function isFromClient(): bool
    {
        return $this->sender_id === $this->conversation->user_id;
    }

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public function attachmentExists(): bool
    {
        return $this->hasAttachment() && self::disk()->exists($this->attachment_path);
    }

    public function attachmentAbsolutePath(): ?string
    {
        return $this->hasAttachment() ? self::disk()->path($this->attachment_path) : null;
    }

    /** Короткая выжимка для списка переписок и уведомлений. */
    public function preview(int $limit = 90): string
    {
        if ($this->body !== null && trim($this->body) !== '') {
            return Str::limit(trim($this->body), $limit);
        }

        return $this->hasAttachment() ? 'Фотография' : '';
    }
}
