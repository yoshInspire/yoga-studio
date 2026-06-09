<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OfferStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(OfferStorage::DISK);
    }

    public function test_offer_returns_not_found_when_missing(): void
    {
        $this->get(route('offer.show'))
            ->assertNotFound();
    }

    public function test_offer_is_viewable_inline_when_uploaded(): void
    {
        Storage::disk(OfferStorage::DISK)->put(OfferStorage::PATH, '%PDF-1.4 test offer');

        $response = $this->get(route('offer.show'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'inline; filename="oferta.pdf"');
    }

    public function test_account_shows_offer_link_when_available(): void
    {
        Storage::disk(OfferStorage::DISK)->put(OfferStorage::PATH, '%PDF-1.4 test offer');

        $client = User::factory()->create();

        $this->actingAs($client)
            ->get(route('account'))
            ->assertOk()
            ->assertSee(route('offer.show'), false);
    }

    public function test_account_hides_offer_link_when_missing(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)
            ->get(route('account'))
            ->assertOk()
            ->assertDontSee(route('offer.show'), false);
    }
}
