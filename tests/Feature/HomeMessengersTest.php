<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeMessengersTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_hero_shows_messenger_links(): void
    {
        config([
            'studio.socials.telegram' => 'https://t.me/yogAvLife',
            'studio.socials.whatsapp' => 'https://wa.me/79647834353',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $hero = substr($html, strpos($html, 'class="hero"'), 2000);
        $this->assertStringContainsString('messengers--hero', $hero);
        $this->assertStringContainsString('href="https://t.me/yogAvLife"', $hero);
        $this->assertStringContainsString('href="https://wa.me/79647834353"', $hero);
    }

    public function test_hero_messengers_hidden_when_links_are_empty(): void
    {
        config(['studio.socials.telegram' => '', 'studio.socials.whatsapp' => '']);

        $this->get('/')->assertOk()->assertDontSee('messengers--hero', false);
    }
}
