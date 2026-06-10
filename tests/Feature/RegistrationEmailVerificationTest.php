<?php

namespace Tests\Feature;

use App\Mail\RegistrationVerificationMail;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

  public function test_registration_without_telegram_requires_email_verification(): void
    {
        Mail::fake();

        $response = $this->post(route('register'), [
            'first_name' => 'Мария',
            'last_name' => 'Иванова',
            'birth_day' => 5,
            'birth_month' => 6,
            'birth_year' => 1995,
            'phone' => '+79991234567',
            'email' => 'maria@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'offer_accepted' => '1',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('auth_tab', 'verify-email');
        $this->assertGuest();

        Mail::assertSent(RegistrationVerificationMail::class);
    }

    public function test_correct_verification_code_completes_registration(): void
    {
        Mail::fake();

        $this->post(route('register'), [
            'first_name' => 'Мария',
            'last_name' => 'Иванова',
            'birth_day' => 5,
            'birth_month' => 6,
            'birth_year' => 1995,
            'phone' => '+79991234567',
            'email' => 'maria@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'offer_accepted' => '1',
        ]);

        $code = null;
        Mail::assertSent(RegistrationVerificationMail::class, function (RegistrationVerificationMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $response = $this->post(route('register.verify'), [
            'code' => $code,
        ]);

        $response->assertRedirect(route('account'));

        $user = User::query()->where('phone', PhoneNormalizer::normalize('+79991234567'))->first();

        $this->assertNotNull($user);
        $this->assertSame('maria@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->offer_accepted_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_verification_code_is_rejected(): void
    {
        Mail::fake();

        $this->post(route('register'), [
            'first_name' => 'Мария',
            'last_name' => 'Иванова',
            'birth_day' => 5,
            'birth_month' => 6,
            'phone' => '+79991234568',
            'email' => 'wrong@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'offer_accepted' => '1',
        ]);

        $response = $this->post(route('register.verify'), [
            'code' => '000000',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('code', 'verify');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'wrong@example.com']);
    }

    public function test_registration_without_email_is_rejected(): void
    {
        Mail::fake();

        $response = $this->post(route('register'), [
            'first_name' => 'Мария',
            'last_name' => 'Иванова',
            'birth_day' => 5,
            'birth_month' => 6,
            'phone' => '+79991234569',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'offer_accepted' => '1',
        ]);

        $response->assertSessionHasErrors('email', 'register');
        Mail::assertNothingSent();
    }

    public function test_cancel_verification_clears_pending_state(): void
    {
        Mail::fake();

        $this->post(route('register'), [
            'first_name' => 'Мария',
            'last_name' => 'Иванова',
            'birth_day' => 5,
            'birth_month' => 6,
            'phone' => '+79991234570',
            'email' => 'cancel@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'offer_accepted' => '1',
        ]);

        $sessionId = session()->getId();
        $this->assertTrue(Cache::has('registration_email_pending:'.$sessionId));

        $this->post(route('register.cancel'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('auth_tab', 'register');

        $this->assertFalse(Cache::has('registration_email_pending:'.$sessionId));
    }
}
