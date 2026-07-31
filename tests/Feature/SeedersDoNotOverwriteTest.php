<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Direction;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DirectionSeeder;
use Database\Seeders\TrainerUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Эти сидеры запускаются на каждом деплое. Они обязаны только доводить
 * недостающие записи — правки, сделанные в админке, трогать нельзя.
 */
class SeedersDoNotOverwriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_renamed_direction_survives_deploy(): void
    {
        $this->seed(DirectionSeeder::class);

        $direction = Direction::query()->firstOrFail();
        $slug = $direction->slug;

        $direction->update([
            'title' => 'Стретчинг по-новому',
            'lead' => 'Текст, который написала студия',
        ]);

        // Второй прогон — это как повторный деплой.
        $this->seed(DirectionSeeder::class);

        $fresh = Direction::query()->where('slug', $slug)->firstOrFail();
        $this->assertSame('Стретчинг по-новому', $fresh->title);
        $this->assertSame('Текст, который написала студия', $fresh->lead);
    }

    public function test_missing_direction_is_still_created(): void
    {
        $this->seed(DirectionSeeder::class);
        $total = Direction::query()->count();
        $this->assertGreaterThan(0, $total);

        Direction::query()->firstOrFail()->delete();
        $this->seed(DirectionSeeder::class);

        $this->assertSame($total, Direction::query()->count());
    }

    public function test_unpublished_direction_is_not_republished(): void
    {
        $this->seed(DirectionSeeder::class);

        $direction = Direction::query()->firstOrFail();
        $direction->update(['is_published' => false]);

        $this->seed(DirectionSeeder::class);

        $this->assertFalse($direction->fresh()->is_published);
    }

    public function test_admin_password_and_name_survive_deploy(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::query()->where('role', UserRole::Admin)->firstOrFail();
        $admin->update([
            'first_name' => 'Ирина',
            'last_name' => 'Коленцева',
            'password' => 'MyOwnSecret2026!',
        ]);

        $this->seed(AdminUserSeeder::class);

        $fresh = $admin->fresh();
        $this->assertSame('Ирина', $fresh->first_name);
        $this->assertSame('Коленцева', $fresh->last_name);
        $this->assertTrue(
            Hash::check('MyOwnSecret2026!', $fresh->password),
            'Деплой не должен сбрасывать пароль администратора.',
        );
    }

    public function test_admin_is_created_when_missing(): void
    {
        $this->assertSame(0, User::query()->where('role', UserRole::Admin)->count());

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::query()->where('role', UserRole::Admin)->count());
    }

    public function test_trainer_details_survive_deploy(): void
    {
        $this->seed(TrainerUserSeeder::class);

        $trainer = User::query()->where('role', UserRole::Trainer)->firstOrFail();
        $trainer->update(['first_name' => 'Мария', 'password' => 'TrainerOwn2026!']);

        $this->seed(TrainerUserSeeder::class);

        $fresh = $trainer->fresh();
        $this->assertSame('Мария', $fresh->first_name);
        $this->assertTrue(Hash::check('TrainerOwn2026!', $fresh->password));
    }

    public function test_repeated_seeding_does_not_duplicate_records(): void
    {
        $this->seed(DirectionSeeder::class);
        $this->seed(AdminUserSeeder::class);
        $this->seed(TrainerUserSeeder::class);

        $directions = Direction::query()->count();
        $users = User::query()->count();

        $this->seed(DirectionSeeder::class);
        $this->seed(AdminUserSeeder::class);
        $this->seed(TrainerUserSeeder::class);

        $this->assertSame($directions, Direction::query()->count());
        $this->assertSame($users, User::query()->count());
    }
}
