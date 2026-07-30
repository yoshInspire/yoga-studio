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

/**
 * Отчёт «Абонементы» должен показывать тот же остаток, что и карточка
 * абонемента в админке, включая ручное списание занятий администратором.
 */
class SubscriptionReportBalanceTest extends TestCase
{
    use RefreshDatabase;

    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = app(ReportService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subscriptionWith(int $total, int $used, array $usageDates): Subscription
    {
        $user = User::create([
            'first_name' => 'Екатерина',
            'last_name' => 'Шульга',
            'phone' => '+7999'.random_int(1000000, 9999999),
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => $total,
            'sessions_used' => $used,
            'purchased_at' => '2026-07-13',
            'starts_at' => '2026-07-13',
            'ends_at' => '2026-08-12',
        ]);

        foreach ($usageDates as $date) {
            SubscriptionUsage::create([
                'subscription_id' => $subscription->id,
                'used_at' => $date,
                'description' => 'Занятие',
                'sessions_spent' => 1,
            ]);
        }

        return $subscription->load('usages');
    }

    /** Всего − Использовано − Забронировано всегда равно Остатку. */
    private function assertRowAddsUp(Subscription $subscription): void
    {
        $used = $this->reports->completedSessionsUsed($subscription);
        $reserved = $this->reports->reservedSessionsAsOf($subscription);

        $this->assertSame(
            $subscription->sessionsRemaining(),
            $subscription->sessions_total - $used - $reserved,
            'Строка отчёта не сходится: всего − использовано − забронировано ≠ остаток.',
        );
    }

    public function test_report_remaining_matches_admin_card_with_manual_deduction(): void
    {
        Carbon::setTestNow('2026-07-14 12:00:00');

        // Одно прошедшее занятие, одна запись на будущее и одно занятие,
        // списанное администратором вручную: 1 + 1 + 1 = 3 из 8.
        $subscription = $this->subscriptionWith(8, 3, [
            '2026-07-13 10:00:00',
            '2026-07-20 10:00:00',
        ]);

        $this->assertSame(5, $subscription->sessionsRemaining());
        $this->assertSame(2, $this->reports->completedSessionsUsed($subscription));
        $this->assertSame(1, $this->reports->reservedSessionsAsOf($subscription));
        $this->assertRowAddsUp($subscription);
    }

    public function test_manual_deduction_without_bookings_is_reflected(): void
    {
        Carbon::setTestNow('2026-07-14 12:00:00');

        $subscription = $this->subscriptionWith(8, 3, ['2026-07-13 10:00:00']);

        $this->assertSame(5, $subscription->sessionsRemaining());
        $this->assertSame(3, $this->reports->completedSessionsUsed($subscription));
        $this->assertSame(0, $this->reports->reservedSessionsAsOf($subscription));
        $this->assertRowAddsUp($subscription);
    }

    public function test_future_bookings_only_are_counted_as_reserved(): void
    {
        Carbon::setTestNow('2026-07-14 12:00:00');

        $subscription = $this->subscriptionWith(8, 2, [
            '2026-07-20 10:00:00',
            '2026-07-22 10:00:00',
        ]);

        $this->assertSame(6, $subscription->sessionsRemaining());
        $this->assertSame(0, $this->reports->completedSessionsUsed($subscription));
        $this->assertSame(2, $this->reports->reservedSessionsAsOf($subscription));
        $this->assertRowAddsUp($subscription);
    }

    public function test_admin_correction_downwards_still_adds_up(): void
    {
        Carbon::setTestNow('2026-07-14 12:00:00');

        // Администратор откатил лишнее списание: занятий в usages больше,
        // чем указано в балансе абонемента.
        $subscription = $this->subscriptionWith(8, 1, [
            '2026-07-13 10:00:00',
            '2026-07-13 18:00:00',
        ]);

        $this->assertSame(7, $subscription->sessionsRemaining());
        $this->assertRowAddsUp($subscription);
    }

    public function test_untouched_subscription_reports_full_balance(): void
    {
        Carbon::setTestNow('2026-07-14 12:00:00');

        $subscription = $this->subscriptionWith(8, 0, []);

        $this->assertSame(8, $subscription->sessionsRemaining());
        $this->assertSame(0, $this->reports->completedSessionsUsed($subscription));
        $this->assertSame(0, $this->reports->reservedSessionsAsOf($subscription));
        $this->assertRowAddsUp($subscription);
    }
}
