<?php

namespace Tests\Feature;

use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotifierTest extends TestCase
{
    public function test_send_delivers_message_via_bot_api(): void
    {
        config(['services.telegram.bot_token' => '123456:TESTTOKEN']);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->assertTrue(app(TelegramNotifier::class)->send(1160272287, '<b>Тест</b>'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === 1160272287);
    }

    public function test_send_retries_without_html_when_first_attempt_fails(): void
    {
        config(['services.telegram.bot_token' => '123456:TESTTOKEN']);

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => false, 'description' => "Can't parse entities"], 400)
                ->push(['ok' => true], 200),
        ]);

        $this->assertTrue(app(TelegramNotifier::class)->send(1, '<b>Заголовок</b>'));

        Http::assertSentCount(2);
    }

    public function test_send_returns_false_when_bot_token_missing(): void
    {
        config(['services.telegram.bot_token' => null]);

        Http::fake();

        $this->assertFalse(app(TelegramNotifier::class)->send(1, 'Тест'));

        Http::assertNothingSent();
    }
}
