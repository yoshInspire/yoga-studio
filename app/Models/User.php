<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\PhoneNormalizer;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'first_name',
    'last_name',
    'patronymic',
    'email',
    'phone',
    'birth_day',
    'birth_month',
    'birth_year',
    'role',
    'password',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'birth_day' => 'integer',
            'birth_month' => 'integer',
            'birth_year' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->phone !== null) {
                $user->phone = PhoneNormalizer::normalize($user->phone) ?? $user->phone;
            }

            if ($user->email !== null) {
                $user->email = mb_strtolower(trim($user->email));
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function getNameAttribute(): string
    {
        return $this->fullName();
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->last_name,
            $this->first_name,
            $this->patronymic,
        ])));
    }

    public function shortName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function initials(): string
    {
        $first = mb_substr($this->first_name ?? '', 0, 1);
        $last = mb_substr($this->last_name ?? '', 0, 1);

        return mb_strtoupper($first.$last);
    }

    public function formattedPhone(): ?string
    {
        return PhoneNormalizer::format($this->phone);
    }

    public function formattedBirthDate(): ?string
    {
        if (! $this->birth_day || ! $this->birth_month) {
            return null;
        }

        $months = [
            1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
            5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
            9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
        ];

        $text = $this->birth_day.' '.$months[$this->birth_month];

        if ($this->birth_year) {
            $text .= ' '.$this->birth_year;
        }

        return $text;
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    public static function findByLogin(string $login): ?self
    {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        if (str_contains($login, '@')) {
            return static::query()
                ->where('email', mb_strtolower($login))
                ->first();
        }

        $phone = PhoneNormalizer::normalize($login);

        if ($phone === null) {
            return null;
        }

        return static::query()->where('phone', $phone)->first();
    }
}
