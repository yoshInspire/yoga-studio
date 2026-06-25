<?php

namespace Tests\Feature;

use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Subscription;
use App\Models\SubscriptionUsage;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SubscriptionReportTest extends TestCase
{
    use RefreshDatabase;

    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = app(ReportService::class);
    }

    public function test_subscription_report_excludes_future_usages_from_balance_and_dates(): void
    {
        Carbon::setTestNow('2026-06-24 07:30:00');

        $user = User::create([
            'first_name' => 'Анна',
            'last_name' => 'Иванова',
            'phone' => '+79990000099',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 2,
            'purchased_at' => '2026-06-01',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-30',
        ]);

        SubscriptionUsage::create([
            'subscription_id' => $subscription->id,
            'used_at' => '2026-06-20 10:00:00',
            'description' => 'Прошедшее занятие',
            'sessions_spent' => 1,
        ]);

        SubscriptionUsage::create([
            'subscription_id' => $subscription->id,
            'used_at' => '2026-06-26 10:00:00',
            'description' => 'Будущая запись',
            'sessions_spent' => 1,
        ]);

        $subscription->load('usages');

        $this->assertSame(1, $this->reports->completedSessionsUsed($subscription));
        $this->assertSame(3, $this->reports->sessionsRemainingAsOf($subscription));
        $this->assertSame('20.06.2026', $this->reports->visitDatesForSubscription($subscription));

        Carbon::setTestNow();
    }

    public function test_subscription_report_includes_today_class_after_it_starts(): void
    {
        Carbon::setTestNow('2026-06-24 12:00:00');

        $user = User::create([
            'first_name' => 'Пётр',
            'last_name' => 'Сидоров',
            'phone' => '+79990000098',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 1,
            'purchased_at' => '2026-06-01',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-30',
        ]);

        SubscriptionUsage::create([
            'subscription_id' => $subscription->id,
            'used_at' => '2026-06-24 10:00:00',
            'description' => 'Занятие сегодня утром',
            'sessions_spent' => 1,
        ]);

        $subscription->load('usages');

        $this->assertSame(1, $this->reports->completedSessionsUsed($subscription));
        $this->assertSame(3, $this->reports->sessionsRemainingAsOf($subscription));
        $this->assertSame('24.06.2026', $this->reports->visitDatesForSubscription($subscription));

        Carbon::setTestNow();
    }
}
