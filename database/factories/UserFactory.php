<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'patronymic' => fake()->optional()->firstNameMale(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => PhoneNormalizer::normalize('79'.fake()->numerify('#########')),
            'birth_day' => fake()->numberBetween(1, 28),
            'birth_month' => fake()->numberBetween(1, 12),
            'birth_year' => fake()->optional()->numberBetween(1970, 2000),
            'role' => UserRole::Client,
            'offer_accepted_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function trainer(): static
    {
        return $this->state(fn () => ['role' => UserRole::Trainer]);
    }
}
