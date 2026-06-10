<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ClientAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

class ClientAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_temporary_password_updates_password_and_sends_email(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'role' => UserRole::Client,
            'email' => 'client@example.com',
            'password' => 'old-password-1',
        ]);

        $oldHash = $user->password;

        $result = app(ClientAccessService::class)->sendTemporaryPassword($user);

        $this->assertNotSame($oldHash, $user->fresh()->password);
        $this->assertTrue(Hash::check($result['password'], $user->fresh()->password));
        $this->assertTrue($result['email']);
        Mail::assertSent(\App\Mail\StudioNotificationMail::class);
    }

    public function test_send_temporary_password_works_for_trainer(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'role' => UserRole::Trainer,
            'email' => 'trainer@example.com',
            'password' => 'old-password-1',
        ]);

        $result = app(ClientAccessService::class)->sendTemporaryPassword($user);

        $this->assertTrue($result['email']);
        Mail::assertSent(\App\Mail\StudioNotificationMail::class);
    }

    public function test_send_temporary_password_requires_delivery_channel(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Client,
            'email' => null,
            'telegram_id' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(ClientAccessService::class)->sendTemporaryPassword($user);
    }
}
