<?php

namespace Tests\Feature;

use App\Mail\RegistrationVerificationMail;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

  private function profilePayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'patronymic' => 'Сергеевич',
            'birth_day' => 12,
            'birth_month' => 3,
            'birth_year' => 1990,
            'phone' => $user->formattedPhone() ?? '+79991234567',
            'email' => $user->email,
        ], $overrides);
    }

    public function test_client_can_update_profile_without_changing_email(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Мария',
            'last_name' => 'Иванова',
            'email' => 'maria@example.com',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->put(route('account.profile.update'), $this->profilePayload($user, [
            'first_name' => 'Мария',
            'last_name' => 'Сидорова',
            'patronymic' => null,
        ]));

        $response->assertRedirect(route('account'));
        $response->assertSessionHas('status');

        $user->refresh();
        $this->assertSame('Сидорова', $user->last_name);
        $this->assertNull($user->patronymic);
        $this->assertSame('maria@example.com', $user->email);
    }

    public function test_client_can_update_profile_when_email_not_set(): void
    {
        $user = User::factory()->create([
            'email' => null,
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($user)->put(route('account.profile.update'), $this->profilePayload($user, [
            'email' => null,
            'first_name' => 'Анна',
        ]));

        $response->assertRedirect(route('account'));

        $user->refresh();
        $this->assertSame('Анна', $user->first_name);
        $this->assertNull($user->email);
    }

    public function test_new_email_requires_verification_before_save(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->put(route('account.profile.update'), $this->profilePayload($user, [
            'email' => 'new@example.com',
        ]));

        $response->assertRedirect(route('account'));
        $response->assertSessionHasErrors('email', null, 'profile');
        $response->assertSessionHas('profile_edit_open', true);

        $user->refresh();
        $this->assertSame('old@example.com', $user->email);
    }

    public function test_client_can_verify_email_and_update_profile(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->post(route('account.profile.email.send'), [
            'email' => 'new@example.com',
        ]);

        $code = null;
        Mail::assertSent(RegistrationVerificationMail::class, function (RegistrationVerificationMail $mail) use (&$code) {
            $code = $mail->code;

            return $mail->context === 'profile';
        });

        $verifyResponse = $this->actingAs($user)->post(route('account.profile.email.verify'), [
            'email' => 'new@example.com',
            'code' => $code,
        ]);

        $verifyResponse->assertRedirect(route('account'));
        $verifyResponse->assertSessionHas('profile_email_verified', 'new@example.com');

        $updateResponse = $this->actingAs($user)->put(route('account.profile.update'), $this->profilePayload($user, [
            'email' => 'new@example.com',
        ]));

        $updateResponse->assertRedirect(route('account'));
        $updateResponse->assertSessionHas('status');

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_wrong_profile_email_code_is_rejected(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->post(route('account.profile.email.send'), [
            'email' => 'new@example.com',
        ]);

        $response = $this->actingAs($user)->post(route('account.profile.email.verify'), [
            'email' => 'new@example.com',
            'code' => '000000',
        ]);

        $response->assertRedirect(route('account'));
        $response->assertSessionHasErrors('code', null, 'profile');
    }

    public function test_client_can_add_email_after_verification(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => null,
            'email_verified_at' => null,
        ]);

        $this->actingAs($user)->post(route('account.profile.email.send'), [
            'email' => 'first@example.com',
        ]);

        $code = null;
        Mail::assertSent(RegistrationVerificationMail::class, function (RegistrationVerificationMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->actingAs($user)->post(route('account.profile.email.verify'), [
            'email' => 'first@example.com',
            'code' => $code,
        ]);

        $this->actingAs($user)->put(route('account.profile.update'), $this->profilePayload($user, [
            'email' => 'first@example.com',
        ]));

        $user->refresh();
        $this->assertSame('first@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_send_code_rejects_email_already_used_by_another_user(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $user = User::factory()->create([
            'email' => 'mine@example.com',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('account.profile.email.send'), [
            'email' => 'taken@example.com',
        ]);

        $response->assertRedirect(route('account'));
        $response->assertSessionHasErrors('email', null, 'profile');
    }

    public function test_phone_must_remain_unique_on_profile_update(): void
    {
        $existingPhone = PhoneNormalizer::normalize('79990001122');
        User::factory()->create(['phone' => $existingPhone]);

        $user = User::factory()->create([
            'phone' => PhoneNormalizer::normalize('79990003344'),
        ]);

        $response = $this->actingAs($user)->put(route('account.profile.update'), $this->profilePayload($user, [
            'phone' => '+7 (990) 001-12-2',
        ]));

        $response->assertRedirect(route('account'));
        $response->assertSessionHasErrors('phone', null, 'profile');
    }
}
