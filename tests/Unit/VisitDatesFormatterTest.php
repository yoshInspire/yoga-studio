<?php

namespace Tests\Unit;

use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Subscription;
use App\Models\User;
use App\Support\VisitDatesFormatter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VisitDatesFormatterTest extends TestCase
{
    #[Test]
    public function test_normalizes_short_dates_using_subscription_year(): void
    {
        $subscription = $this->makeSubscription('2026-06-08', '2026-07-07');

        $this->assertSame(
            '08.06.2026, 11.06.2026, 12.06.2026',
            VisitDatesFormatter::normalizeList('08.06, 11.06, 12.06', $subscription),
        );
    }

    #[Test]
    public function test_keeps_dates_that_already_have_year(): void
    {
        $subscription = $this->makeSubscription('2026-06-08', '2026-07-07');

        $this->assertSame(
            '08.06.2026',
            VisitDatesFormatter::normalizeSingle('08.06.2026', $subscription),
        );
    }

    private function makeSubscription(string $startsAt, string $endsAt): Subscription
    {
        $user = User::make([
            'first_name' => 'Test',
            'last_name' => 'Client',
            'role' => UserRole::Client,
        ]);

        return Subscription::make([
            'user_id' => 1,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => $startsAt,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ])->setRelation('user', $user);
    }
}
