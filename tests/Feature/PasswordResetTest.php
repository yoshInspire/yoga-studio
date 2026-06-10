<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_reset_password_with_code(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'role' => UserRole::Client,
            'phone' => '+79991112233',
            'email' => 'client@example.com',
            'password' => 'old-password-1',
        ]);

        $service = app(PasswordResetService::class);
        $session = $this->app['session']->driver();

        $service->start($session, '+7 (999) 111-22-33');

        Mail::assertSent(\App\Mail\RegistrationVerificationMail::class);

        $code = $this->extractCodeFromMail();

        $updated = $service->reset($session, $code, 'new-password-2');

        $this->assertSame($user->id, $updated->id);
        $this->assertTrue(Hash::check('new-password-2', $updated->fresh()->password));
    }

    public function test_forgot_password_page_shows_reset_form(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Забыли пароль?')
            ->assertSee('Отправить код', false);
    }

    public function test_unknown_phone_shows_error(): void
    {
        $this->post(route('password.forgot'), [
            'phone' => '+79990000000',
        ])
            ->assertSessionHasErrors('phone', null, 'reset');
    }

    private function extractCodeFromMail(): string
    {
        $mailable = null;

        Mail::assertSent(\App\Mail\RegistrationVerificationMail::class, function ($mail) use (&$mailable) {
            $mailable = $mail;

            return true;
        });

        $html = view('emails.registration-verification', [
            'code' => $mailable->code,
            'ttlMinutes' => $mailable->ttlMinutes,
            'context' => $mailable->context,
        ])->render();

        preg_match('/>(\d{6})</', $html, $matches);

        return $matches[1];
    }
}
