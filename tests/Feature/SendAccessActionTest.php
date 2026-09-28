<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Кнопка «Отправить доступ» в карточке пользователя. Раньше без email она
 * молча отключалась — администратор видел бледную кнопку, которая «не работает».
 */
class SendAccessActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_button_is_enabled_for_trainer_without_email_and_explains_why(): void
    {
        $trainer = User::factory()->create(['role' => UserRole::Trainer, 'email' => null, 'telegram_id' => null]);
        $oldHash = $trainer->password;

        Livewire::test(EditUser::class, ['record' => $trainer->getKey()])
            ->assertActionEnabled('sendAccess')
            ->callAction('sendAccess')
            ->assertNotified('Не удалось отправить доступ');

        $this->assertSame($oldHash, $trainer->fresh()->password);
    }

    public function test_unsaved_email_asks_to_save_first(): void
    {
        $trainer = User::factory()->create(['role' => UserRole::Trainer, 'email' => null, 'telegram_id' => null]);

        Livewire::test(EditUser::class, ['record' => $trainer->getKey()])
            ->fillForm(['email' => 'trainer@example.com'])
            ->callAction('sendAccess')
            ->assertNotified('Не удалось отправить доступ');

        $this->assertNull($trainer->fresh()->email);
    }

    public function test_sends_password_when_email_saved(): void
    {
        $trainer = User::factory()->create(['role' => UserRole::Trainer, 'email' => 'trainer@example.com', 'telegram_id' => null]);
        $oldHash = $trainer->password;

        Livewire::test(EditUser::class, ['record' => $trainer->getKey()])
            ->callAction('sendAccess')
            ->assertNotified();

        $this->assertNotSame($oldHash, $trainer->fresh()->password);
    }
}
