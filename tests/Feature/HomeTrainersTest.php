<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTrainersTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_shows_published_trainers_from_database(): void
    {
        User::create([
            'first_name' => 'Ирина',
            'last_name' => 'Коленцева',
            'phone' => '79001112233',
            'role' => UserRole::Trainer,
            'password' => 'secret123',
            'show_on_site' => true,
            'trainer_title' => 'Основатель студии · ведущий тренер',
            'trainer_bio' => 'Ведёт хатха-йогу и йогатерапию.',
        ]);

        User::create([
            'first_name' => 'Скрытый',
            'last_name' => 'Тренер',
            'phone' => '79001112234',
            'role' => UserRole::Trainer,
            'password' => 'secret123',
            'show_on_site' => false,
            'trainer_title' => 'Не должен отображаться',
        ]);

        User::create([
            'first_name' => 'Анна',
            'last_name' => 'Клиент',
            'phone' => '79001112235',
            'role' => UserRole::Client,
            'password' => 'secret123',
            'show_on_site' => true,
            'trainer_title' => 'Клиент не тренер',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Ирина Коленцева');
        $response->assertSee('Основатель студии · ведущий тренер');
        $response->assertSee('Ведёт хатха-йогу и йогатерапию.');
        $response->assertDontSee('Скрытый Тренер');
        $response->assertDontSee('Не должен отображаться');
        $response->assertDontSee('Клиент не тренер');
    }

    public function test_homepage_hides_trainers_grid_when_none_published(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('teacher__name', false);
    }
}
