<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Exports\Sheets\SubscriptionTypeSheet;
use App\Models\Payment;
use App\Models\PaymentItem;
use App\Models\Subscription;
use App\Models\SubscriptionUsage;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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

    public function test_subscription_report_normalizes_imported_visit_dates_without_year(): void
    {
        $user = User::create([
            'first_name' => 'Ольга',
            'last_name' => 'Кременскова',
            'phone' => '+79031898884',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 3,
            'purchased_at' => '2026-06-07',
            'starts_at' => '2026-06-08',
            'ends_at' => '2026-07-07',
            'admin_note' => 'Импорт из старой системы (14.06.2026). Посещения: 08.06, 11.06, 12.06',
        ]);

        $this->assertSame(
            '08.06.2026, 11.06.2026, 12.06.2026',
            $this->reports->visitDatesForSubscription($subscription),
        );
    }

    public function test_subscription_report_lists_same_day_visit_twice(): void
    {
        Carbon::setTestNow('2026-07-03 10:00:00');

        $user = User::create([
            'first_name' => 'Ирина',
            'last_name' => 'Лобанова',
            'phone' => '+79939047684',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 8,
            'sessions_used' => 3,
            'purchased_at' => '2026-06-12',
            'starts_at' => '2026-06-12',
            'ends_at' => '2026-07-11',
        ]);

        SubscriptionUsage::create([
            'subscription_id' => $subscription->id,
            'used_at' => '2026-06-26 10:00:00',
            'description' => 'Утреннее занятие',
            'sessions_spent' => 1,
        ]);

        SubscriptionUsage::create([
            'subscription_id' => $subscription->id,
            'used_at' => '2026-07-02 19:15:00',
            'description' => 'Йога-нидра',
            'sessions_spent' => 1,
        ]);

        SubscriptionUsage::create([
            'subscription_id' => $subscription->id,
            'used_at' => '2026-07-02 20:30:00',
            'description' => 'Stic Mobility Yoga',
            'sessions_spent' => 1,
        ]);

        $subscription->load('usages');

        $this->assertSame(3, $this->reports->completedSessionsUsed($subscription));
        $this->assertSame(
            '26.06.2026, 02.07.2026, 02.07.2026',
            $this->reports->visitDatesForSubscription($subscription),
        );

        Carbon::setTestNow();
    }

    public function test_subscription_report_respects_manually_set_sessions_used(): void
    {
        Carbon::setTestNow('2026-07-05 12:00:00');

        $user = User::create([
            'first_name' => 'Виктория',
            'last_name' => 'Титова',
            'phone' => '+79175603520',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Individual,
            'sessions_total' => 4,
            'sessions_used' => 1,
            'purchased_at' => '2026-07-03',
            'starts_at' => '2026-07-03',
            'ends_at' => '2026-08-01',
        ]);

        $subscription->load('usages');

        $this->assertSame(1, $this->reports->completedSessionsUsed($subscription));
        $this->assertSame(3, $this->reports->sessionsRemainingAsOf($subscription));

        Carbon::setTestNow();
    }

    public function test_subscription_sheet_shows_purchase_price_and_leaves_it_empty_without_payment(): void
    {
        Carbon::setTestNow('2026-08-12 09:00:00');

        $user = User::create([
            'first_name' => 'Анастасия',
            'last_name' => 'Фадеева',
            'phone' => '+79164397663',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $bought = Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Individual,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => '2026-08-01',
            'starts_at' => '2026-08-01',
            'ends_at' => '2026-08-30',
        ]);

        // Абонемент из старой системы: платежа не было, цену студия знает сама.
        $imported = Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Individual,
            'sessions_total' => 1,
            'sessions_used' => 0,
            'purchased_at' => '2026-08-02',
            'starts_at' => '2026-08-02',
            'ends_at' => '2026-08-31',
            'admin_note' => 'Импорт из старой системы',
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'product_key' => 'individual_4',
            'amount' => 13200,
            'currency' => 'RUB',
            'status' => PaymentStatus::Succeeded,
            'starts_at' => '2026-08-01',
            'description' => 'Абонемент · 4 занятия',
            'idempotence_key' => (string) Str::uuid(),
        ]);

        PaymentItem::create([
            'payment_id' => $payment->id,
            'subscription_id' => $bought->id,
            'product_key' => 'individual_4',
            'name' => 'Абонемент · 4 занятия',
            'type' => SubscriptionType::Individual,
            'price' => 13200,
            'sessions' => 4,
            'validity_days' => 30,
        ]);

        $this->assertSame(13200, $bought->fresh()->price());
        $this->assertNull($imported->fresh()->price());

        $rows = (new SubscriptionTypeSheet(SubscriptionType::Individual, $this->reports))
            ->collection();

        $headings = (new SubscriptionTypeSheet(SubscriptionType::Individual, $this->reports))
            ->headings();

        $priceColumn = array_search('Цена, ₽', $headings, true);
        $this->assertNotFalse($priceColumn, 'В отчёте нет столбца с ценой.');
        $this->assertSame('Дата покупки', $headings[$priceColumn - 1]);

        // Числом, а не строкой — иначе столбец не суммируется в Excel.
        $this->assertSame(13200, $rows[0][$priceColumn]);
        $this->assertSame('', $rows[1][$priceColumn]);

        Carbon::setTestNow();
    }
}
