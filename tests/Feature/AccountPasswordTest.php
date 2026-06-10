<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_change_password_in_account(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Client,
            'password' => 'old-password-1',
        ]);

        $this->actingAs($user)
            ->from(route('account'))
            ->put(route('account.password.update'), [
                'current_password' => 'old-password-1',
                'password' => 'new-password-2',
                'password_confirmation' => 'new-password-2',
            ])
            ->assertRedirect(route('account'))
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('new-password-2', $user->fresh()->password));
    }

    public function test_client_cannot_change_password_with_wrong_current_password(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Client,
            'password' => 'old-password-1',
        ]);

        $this->actingAs($user)
            ->from(route('account'))
            ->put(route('account.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password-2',
                'password_confirmation' => 'new-password-2',
            ])
            ->assertSessionHasErrors('current_password', null, 'password');
    }
}
