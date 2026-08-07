<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\PhoneNormalizer;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'first_name',
    'last_name',
    'patronymic',
    'avatar_path',
    'email',
    'phone',
    'telegram_id',
    'telegram_username',
    'telegram_linked_at',
    'birth_day',
    'birth_month',
    'birth_year',
    'health_note',
    'health_note_visible_to_trainer',
    'role',
    'trainer_photo_path',
    'trainer_title',
    'trainer_bio',
    'show_on_site',
    'site_sort_order',
    'password',
    'offer_accepted_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'offer_accepted_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'telegram_id' => 'integer',
            'telegram_linked_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'show_on_site' => 'boolean',
            'health_note_visible_to_trainer' => 'boolean',
            'site_sort_order' => 'integer',
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

    /** Аккаунт удалён самим клиентом: личные данные стёрты, история платежей осталась. */
    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
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

    public function hasBirthdayOn(\Illuminate\Support\Carbon $date): bool
    {
        if (! $this->birth_day || ! $this->birth_month) {
            return false;
        }

        if ($this->birth_month === 2 && $this->birth_day === 29 && ! $date->isLeapYear()) {
            return $date->month === 2 && $date->day === 28;
        }

        return $this->birth_month === $date->month && $this->birth_day === $date->day;
    }

    /** @param  Builder<self>  $query */
    public function scopeBirthdayOn(Builder $query, \Illuminate\Support\Carbon $date): Builder
    {
        return $query
            ->whereNotNull('birth_day')
            ->whereNotNull('birth_month')
            ->where(function (Builder $query) use ($date) {
                $query->where(function (Builder $query) use ($date) {
                    $query->where('birth_month', $date->month)
                        ->where('birth_day', $date->day);
                });

                if ($date->month === 2 && $date->day === 28 && ! $date->isLeapYear()) {
                    $query->orWhere(function (Builder $query) {
                        $query->where('birth_month', 2)
                            ->where('birth_day', 29);
                    });
                }
            });
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    public function isTrainer(): bool
    {
        return $this->role === UserRole::Trainer;
    }

    public function canReactToNews(): bool
    {
        return in_array($this->role, [UserRole::Client, UserRole::Trainer], true);
    }

    public function trainerDisplayName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function hasAvatar(): bool
    {
        return $this->avatar_path !== null;
    }

    /**
     * Ссылка на фотографию пользователя.
     *
     * Абсолютная: её берёт и вёрстка сайта, и мобильное приложение, которое
     * ходит на сервер по сети и относительный путь развернуть не может.
     */
    public function avatarUrl(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    public function trainerPhotoUrl(): ?string
    {
        if (! $this->trainer_photo_path) {
            return null;
        }

        return Storage::disk('public')->url($this->trainer_photo_path);
    }

    /** @param  Builder<self>  $query */
    public function scopePublishedOnSite(Builder $query): Builder
    {
        return $query
            ->where('role', UserRole::Trainer)
            ->where('show_on_site', true)
            ->orderBy('site_sort_order')
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function hasTelegram(): bool
    {
        return $this->telegram_id !== null;
    }

    public function hasAcceptedOffer(): bool
    {
        return $this->offer_accepted_at !== null;
    }

    public function formattedOfferAcceptedAt(): ?string
    {
        return $this->offer_accepted_at?->format('d.m.Y H:i');
    }

    public function telegramDisplayAccount(): ?string
    {
        if (! $this->hasTelegram()) {
            return null;
        }

        if ($this->telegram_username) {
            return '@'.$this->telegram_username;
        }

        return 'Telegram #'.$this->telegram_id;
    }

    /**
     * Ссылка на кабинет с публичного сайта. Админка (/admin) сюда не попадает.
     *
     * @return array{url: string, label: string}|null
     */
    public function publicCabinetLink(): ?array
    {
        return match ($this->role) {
            UserRole::Client => [
                'url' => route('account'),
                'label' => 'Личный кабинет',
            ],
            UserRole::Trainer => [
                'url' => route('trainer'),
                'label' => 'Кабинет тренера',
            ],
            UserRole::Admin => null,
        };
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function trainedSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'trainer_id');
    }

    /** Лента уведомлений в мобильном приложении. */
    public function clientNotifications(): HasMany
    {
        return $this->hasMany(ClientNotification::class);
    }

    /** Устройства, на которые уходят пуши. */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    public static function findByPhone(?string $phone): ?self
    {
        $normalized = PhoneNormalizer::normalize($phone);

        if ($normalized === null) {
            return null;
        }

        return static::query()->where('phone', $normalized)->first();
    }
}
