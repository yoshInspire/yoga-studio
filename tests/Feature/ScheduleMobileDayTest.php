<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Models\ClassSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Лента дней расписания на телефоне: какой день открыт и где есть занятия.
 */
class ScheduleMobileDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_day_with_classes_is_opened_and_marked(): void
    {
        ClassSession::create([
            'topic' => 'Йога-нидра',
            'starts_at' => now()->addDays(6)->setTime(19, 0),
            'type' => SubscriptionType::SpecialEvent,
            'capacity' => 10,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $html = $this->get(route('schedule'))->assertOk()->getContent();

        // Открыт седьмой день ленты, а не сегодняшний пустой.
        $this->assertMatchesRegularExpression('/gridsched__mobtab is-active[^"]*gridsched__mobtab--busy"\s+data-day-tab="6"/', $html);
        $this->assertMatchesRegularExpression('/gridsched__mobile-panel "\s+data-day-panel="6"/', $html);
        $this->assertMatchesRegularExpression('/gridsched__mobile-panel is-hidden"\s+data-day-panel="0"/', $html);
        $this->assertSame(1, substr_count($html, 'gridsched__mobtab--busy"'));
    }

    public function test_today_stays_open_when_the_week_is_empty(): void
    {
        $html = $this->get(route('schedule'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/gridsched__mobile-panel "\s+data-day-panel="0"/', $html);
        $this->assertStringNotContainsString('gridsched__mobtab--busy', $html);
    }
}
