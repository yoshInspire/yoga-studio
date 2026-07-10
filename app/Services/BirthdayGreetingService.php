<?php

namespace App\Services;

use App\Models\BirthdayGreeting;
use App\Models\ClientMailingLog;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BirthdayGreetingService
{
    /**
     * @return Collection<int, BirthdayGreeting>
     */
    public function ordered(): Collection
    {
        return BirthdayGreeting::query()
            ->orderBy('position')
            ->get();
    }

    /**
     * @return list<string>
     */
    public function orderedBodies(): array
    {
        return $this->ordered()
            ->pluck('body')
            ->all();
    }

    /**
     * @param  list<string>  $bodies
     */
    public function syncBodies(array $bodies): void
    {
        if ($bodies === []) {
            throw new InvalidArgumentException('Нужен хотя бы один вариант поздравления.');
        }

        $normalized = [];

        foreach ($bodies as $index => $body) {
            $text = trim($body);

            if ($text === '') {
                throw new InvalidArgumentException('Текст варианта '.($index + 1).' не может быть пустым.');
            }

            $normalized[] = $text;
        }

        BirthdayGreeting::query()->delete();

        foreach ($normalized as $index => $body) {
            BirthdayGreeting::query()->create([
                'position' => $index + 1,
                'body' => $body,
            ]);
        }
    }

    public function bodyForUser(User $user): ?string
    {
        $greetings = $this->ordered();

        if ($greetings->isEmpty()) {
            return null;
        }

        $sentCount = ClientMailingLog::query()
            ->where('user_id', $user->id)
            ->where('type', ClientMailingLog::TYPE_BIRTHDAY)
            ->count();

        return $greetings->get($sentCount % $greetings->count())?->body;
    }
}
