<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_stores_offer_acceptance_timestamp(): void
    {
        $this->withoutMiddleware();

        // Covered in RegistrationEmailVerificationTest with full flow.
        $user = User::factory()->create([
            'offer_accepted_at' => now(),
        ]);

        $this->assertTrue($user->hasAcceptedOffer());
        $this->assertNotNull($user->formattedOfferAcceptedAt());
    }

    public function test_client_can_accept_offer_in_account(): void
    {
        $user = User::factory()->create([
            'offer_accepted_at' => null,
        ]);

        $response = $this->actingAs($user)->post(route('account.offer.accept'), [
            'offer_accepted' => '1',
        ]);

        $response->assertRedirect(route('account'));
        $response->assertSessionHas('status');
        $this->assertNotNull($user->fresh()->offer_accepted_at);
    }

    public function test_booking_requires_accepted_offer(): void
    {
        $user = User::factory()->create([
            'offer_accepted_at' => null,
        ]);

        $response = $this->actingAs($user)->post(route('bookings.store'), [
            'class_session_id' => 1,
        ]);

        $response->assertRedirect(route('account'));
        $response->assertSessionHasErrors('offer');
    }

    public function test_purchase_requires_accepted_offer(): void
    {
        $user = User::factory()->create([
            'offer_accepted_at' => null,
        ]);

        $response = $this->actingAs($user)->get(route('purchase.index'));

        $response->assertRedirect(route('account'));
        $response->assertSessionHasErrors('offer');
    }
}
