<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Публичные справочные эндпоинты для мобильного приложения:
 * цены и правила студии. Гость должен получать их без токена.
 */
class StudioInfoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pricing_is_public_and_matches_the_site_catalog(): void
    {
        $response = $this->getJson('/api/v1/pricing');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['key', 'title', 'sections' => [['title', 'items' => [['name', 'price', 'highlight']]]], 'notes'],
                ],
            ]);

        $keys = array_column($response->json('data'), 'key');
        $this->assertSame(array_keys(config('pricing')), $keys);

        // Цены приходят числами: приложение форматирует их само.
        $first = $response->json('data.0.sections.0.items.0');
        $this->assertIsInt($first['price']);
        $this->assertGreaterThan(0, $first['price']);
    }

    public function test_rules_are_public_and_come_without_html_markup(): void
    {
        $response = $this->getJson('/api/v1/rules');

        $response->assertOk()
            ->assertJsonStructure(['lead', 'data' => [['q', 'a']]]);

        foreach ($response->json('data') as $item) {
            foreach ($item['a'] as $paragraph) {
                $this->assertStringNotContainsString('<', $paragraph);
                $this->assertStringNotContainsString('&nbsp;', $paragraph);
            }
        }
    }

    public function test_rules_follow_the_cancellation_deadlines_from_config(): void
    {
        config([
            'studio.cancellation.morning_hours' => 21,
            'studio.cancellation.day_hours' => 3,
        ]);

        $answers = collect($this->getJson('/api/v1/rules')->json('data'))
            ->firstWhere('q', 'Отмена бронирования')['a'];

        $this->assertStringContainsString('за 21 час ', $answers[0]);
        $this->assertStringContainsString('за 3 часа', $answers[0]);
    }
}
