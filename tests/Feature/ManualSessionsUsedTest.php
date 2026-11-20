<?php

namespace Tests\Feature;

use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Models\Subscription;
use App\Models\SubscriptionUsage;
use App\Models\User;
use App\Services\ReportService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ручная правка «Списано» в карточке абонемента должна давать дату в отчёте
 * (20.11.2026, Шульга, абонемент #565: «списала +1, а в отчёте не показывает»).
 */
class ManualSessionsUsedTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subscriptionWithOneVisit(): Subscription
    {
        $client = User::factory()->create(['role' => UserRole::Client]);

        $subscription = Subscription::create([
            'user_id' => $client->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 8,
            'sessions_used' => 1,
            'purchased_at' => '2026-11-12',
            'starts_at' => '2026-11-12',
            'ends_at' => '2026-12-11',
        ]);

        SubscriptionUsage::create([
            'subscription_id' => $subscription->id,
            'used_at' => '2026-11-17 19:15:00',
            'description' => 'Хатха-йога · 17.11.2026 19:15',
            'sessions_spent' => 1,
        ]);

        return $subscription;
    }

    private function payload(Subscription $subscription, int $used): array
    {
        return [
            'type' => 'group',
            'sessions_total' => $subscription->sessions_total,
            'sessions_used' => $used,
            'sessions_per_day' => 1,
            'purchased_at' => '2026-11-12',
            'starts_at' => '2026-11-12',
            'ends_at' => '2026-12-11',
        ];
    }

    public function test_manual_increase_from_app_adds_visit_date_to_report(): void
    {
        Carbon::setTestNow('2026-11-19 21:51:00');
        $subscription = $this->subscriptionWithOneVisit();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/subscriptions/{$subscription->id}", $this->payload($subscription, 2))
            ->assertOk();

        Carbon::setTestNow('2026-11-20 10:00:00');
        $subscription = $subscription->fresh()->load('usages');
        $reports = app(ReportService::class);

        $this->assertSame(2, $subscription->sessions_used);
        $this->assertSame(2, $reports->completedSessionsUsed($subscription));
        $this->assertSame('17.11.2026, 19.11.2026', $reports->visitDatesForSubscription($subscription));
    }

    public function test_manual_decrease_removes_manual_rows_but_keeps_booked_ones(): void
    {
        Carbon::setTestNow('2026-11-19 21:51:00');
        $subscription = $this->subscriptionWithOneVisit();
        $service = app(SubscriptionService::class);

        $subscription->update(['sessions_used' => 3]);
        $service->syncManualUsedChange($subscription, 1);
        $this->assertSame(3, $subscription->usages()->count());

        $subscription->update(['sessions_used' => 0]);
        $service->syncManualUsedChange($subscription, 3);

        $this->assertSame(
            ['Хатха-йога · 17.11.2026 19:15'],
            $subscription->usages()->pluck('description')->all(),
        );
    }

    public function test_manual_increase_from_web_admin_adds_usage(): void
    {
        Carbon::setTestNow('2026-11-19 21:51:00');
        $subscription = $this->subscriptionWithOneVisit();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(EditSubscription::class, ['record' => $subscription->getRouteKey()])
            ->fillForm(['sessions_used' => 2])
            ->call('save')
            ->assertHasNoFormErrors();

        $manual = $subscription->usages()
            ->where('description', SubscriptionService::MANUAL_USAGE_DESCRIPTION)
            ->get();

        $this->assertCount(1, $manual);
        $this->assertSame('2026-11-19 21:51', $manual->first()->used_at->format('Y-m-d H:i'));
    }
}
