<?php

namespace App\Support;

readonly class TelegramAuthData
{
    public function __construct(
        public int $id,
        public string $first_name,
        public ?string $last_name,
        public ?string $username,
        public ?string $photo_url,
        public int $auth_date,
    ) {}

    public function displayAccount(): string
    {
        if ($this->username) {
            return '@'.$this->username;
        }

        $name = trim($this->first_name.' '.($this->last_name ?? ''));

        return $name !== '' ? $name : 'Telegram #'.$this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSession(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'username' => $this->username,
            'photo_url' => $this->photo_url,
            'auth_date' => $this->auth_date,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromSession(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            first_name: (string) $data['first_name'],
            last_name: isset($data['last_name']) ? (string) $data['last_name'] : null,
            username: isset($data['username']) ? (string) $data['username'] : null,
            photo_url: isset($data['photo_url']) ? (string) $data['photo_url'] : null,
            auth_date: (int) ($data['auth_date'] ?? 0),
        );
    }
}
