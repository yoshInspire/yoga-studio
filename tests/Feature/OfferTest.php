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

    public function test_offer_pdf_returns_not_found_when_missing(): void
    {
        $this->get(route('legal.offer-pdf'))
            ->assertNotFound();
    }

    public function test_offer_pdf_is_viewable_inline_when_uploaded(): void
    {
        Storage::disk(OfferStorage::DISK)->put(OfferStorage::PATH, '%PDF-1.4 test offer');

        $response = $this->get(route('legal.offer-pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'inline; filename="oferta.pdf"');
    }

    /**
     * Текстовая версия — основной способ прочитать договор: PDF в браузере
     * Android скачивается файлом вместо показа.
     */
    public function test_offer_page_is_public_and_shows_the_contract_text(): void
    {
        $this->get(route('legal.offer'))
            ->assertOk()
            ->assertSee('Договор-оферта')
            ->assertSee('Правила посещения')
            ->assertSee('Противопоказания к групповым практикам', false);
    }

    public function test_offer_page_links_to_the_pdf_only_when_it_is_uploaded(): void
    {
        $this->get(route('legal.offer'))
            ->assertOk()
            ->assertDontSee(route('legal.offer-pdf'), false);

        Storage::disk(OfferStorage::DISK)->put(OfferStorage::PATH, '%PDF-1.4 test offer');

        $this->get(route('legal.offer'))
            ->assertOk()
            ->assertSee(route('legal.offer-pdf'), false);
    }

    public function test_account_links_to_the_offer_page(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)
            ->get(route('account'))
            ->assertOk()
            ->assertSee(route('legal.offer'), false);
    }
}
