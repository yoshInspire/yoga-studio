<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\OfferDocument;
use App\Support\OfferStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Загрузка оферты из приложения (ADMIN_PLAN_2.md, фаза L).
 *
 * Смысл фазы — чтобы у договора не было двух редакций: заказчица грузит PDF,
 * а клиенты и магазины приложений читают страницу `/oferta`. Здесь стережём
 * именно это: страница пересобирается из файла, а когда файл не разобрался —
 * прежний текст остаётся на месте и об этом сказано вслух.
 */
class AdminOfferApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /** @return list<array{type: string, text: string}> */
    private function someBlocks(): array
    {
        return [
            ['type' => 'heading', 'text' => '1. Определения и термины'],
            ['type' => 'paragraph', 'text' => 'Прежняя редакция договора.'],
        ];
    }

    public function test_client_cannot_reach_the_offer_screen(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Client]), 'sanctum')
            ->getJson('/api/v1/admin/offer')
            ->assertForbidden();
    }

    public function test_state_says_there_is_no_file_yet(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/offer')
            ->assertOk()
            ->assertJsonPath('exists', false)
            ->assertJsonPath('blocks', 0)
            ->assertJsonPath('stale', false);
    }

    public function test_state_reports_the_assembled_text(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(OfferStorage::PATH, 'pdf');
        OfferDocument::put($this->someBlocks());

        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/offer')
            ->assertOk()
            ->json();

        $this->assertTrue($payload['exists']);
        $this->assertSame(2, $payload['blocks']);
        $this->assertSame('1. Определения и термины', $payload['preview'][0]);
        $this->assertFalse($payload['stale']);
    }

    /**
     * Файл нечитаемый — сохраняем его, но текст страницы не трогаем.
     *
     * Молча подменить договор нечем, а терять загруженное незачем. Ответ
     * обязан сказать, что редакция на странице осталась прежней.
     */
    public function test_unreadable_pdf_keeps_the_previous_text_and_says_so(): void
    {
        Storage::fake('local');
        OfferDocument::put($this->someBlocks());

        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/offer', [
                'file' => UploadedFile::fake()->create('offer.pdf', 40, 'application/pdf'),
            ])
            ->assertOk()
            ->json();

        // Файл лёг на диск...
        Storage::disk('local')->assertExists(OfferStorage::PATH);
        // ...а текст страницы остался прежним.
        $this->assertFalse($payload['parsed']);
        $this->assertSame(2, $payload['blocks']);
        $this->assertStringContainsString('прежняя редакция', $payload['message']);
        $this->assertSame($this->someBlocks(), OfferDocument::blocks());
    }

    /** Файл новее собранного текста — на экране это видно без открытия сайта. */
    public function test_stale_is_raised_when_the_file_is_newer_than_the_text(): void
    {
        Storage::fake('local');

        // Дата текста живёт в базе и слушается Carbon, а дата файла — это
        // настоящий mtime на диске. Поэтому «текст старше» получаем, отматывая
        // назад запись в базу, а не забегая вперёд с файлом.
        $this->travelTo(now()->subHours(2));
        OfferDocument::put($this->someBlocks());
        $this->travelBack();

        Storage::disk('local')->put(OfferStorage::PATH, 'pdf');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/offer')
            ->assertOk()
            ->assertJsonPath('stale', true);
    }

    public function test_only_pdf_is_accepted(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/offer', [
                'file' => UploadedFile::fake()->image('offer.jpg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /** Удаление убирает и файл, и собранный из него текст: сирот не держим. */
    public function test_delete_removes_the_file_and_the_assembled_text(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(OfferStorage::PATH, 'pdf');
        OfferDocument::put($this->someBlocks());

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/offer')
            ->assertOk()
            ->assertJsonPath('exists', false)
            ->assertJsonPath('blocks', 0);

        Storage::disk('local')->assertMissing(OfferStorage::PATH);
        $this->assertSame([], OfferDocument::blocks());
    }

    /** Страница сайта показывает собранный текст, а не свёрстанный руками. */
    public function test_public_page_renders_the_assembled_text(): void
    {
        OfferDocument::put([
            ['type' => 'heading', 'text' => 'Раздел из загруженного файла'],
            ['type' => 'paragraph', 'text' => 'Абзац из загруженного файла.'],
        ]);

        $this->get('/oferta')
            ->assertOk()
            ->assertSee('Раздел из загруженного файла')
            ->assertSee('Абзац из загруженного файла.')
            // Вёрстка, набранная руками, при этом не показывается.
            ->assertDontSee('возмездный договор об оказании услуг по занятиям');
    }

    /** Текста нет — на странице остаётся прежняя вёрстка, а не пустота. */
    public function test_public_page_falls_back_to_the_handwritten_text(): void
    {
        $this->get('/oferta')
            ->assertOk()
            ->assertSee('1. Определения и термины')
            ->assertSee('возмездный договор об оказании услуг по занятиям', false);
    }
}
