<?php

namespace Tests\Feature;

use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SubscriptionService::class);
    }

    private function user(string $phone = '+79990000001'): User
    {
        return User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => $phone,
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);
    }

    private function subscription(User $user, array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ], $overrides));
    }

    public function test_finds_usable_subscription_of_matching_type(): void
    {
        $user = $this->user();
        $group = $this->subscription($user, ['type' => SubscriptionType::Group]);
        $this->subscription($user, ['type' => SubscriptionType::Individual, 'sessions_total' => 2]);

        $found = $this->service->findUsableForUser($user, SubscriptionType::Group);

        $this->assertNotNull($found);
        $this->assertSame($group->id, $found->id);
    }

    public function test_does_not_find_subscription_without_remaining_sessions(): void
    {
        $user = $this->user();
        $this->subscription($user, ['sessions_total' => 2, 'sessions_used' => 2]);

        $this->assertNull($this->service->findUsableForUser($user, SubscriptionType::Group));
    }

    public function test_does_not_find_expired_subscription(): void
    {
        $user = $this->user();
        $this->subscription($user, [
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);

        $this->assertNull($this->service->findUsableForUser($user, SubscriptionType::Group));
    }

    public function test_deduct_increments_used_and_creates_usage(): void
    {
        $user = $this->user();
        $sub = $this->subscription($user);

        $usage = $this->service->deduct($sub, 'Хатха-йога', now());

        $this->assertSame(1, $sub->fresh()->sessions_used);
        $this->assertSame(3, $sub->fresh()->sessionsRemaining());
        $this->assertDatabaseHas('subscription_usages', ['id' => $usage->id]);
    }

    public function test_refund_decrements_used_and_deletes_usage(): void
    {
        $user = $this->user();
        $sub = $this->subscription($user, ['sessions_used' => 1]);
        $usage = $sub->usages()->create(['used_at' => now(), 'description' => 'test']);

        $this->service->refundUsage($usage);

        $this->assertSame(0, $sub->fresh()->sessions_used);
        $this->assertDatabaseMissing('subscription_usages', ['id' => $usage->id]);
    }

    public function test_types_match_only_when_equal(): void
    {
        $this->assertTrue($this->service->typesMatch(SubscriptionType::Group, SubscriptionType::Group));
        $this->assertFalse($this->service->typesMatch(SubscriptionType::Group, SubscriptionType::Individual));
    }

    public function test_change_start_date_after_end_throws(): void
    {
        $user = $this->user();
        $sub = $this->subscription($user, ['ends_at' => now()->addDays(5)]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->changeStartDate($sub, Carbon::parse(now()->addDays(10)));
    }

    public function test_deducts_from_earliest_purchased_subscription_first(): void
    {
        $user = $this->user();

        // Более поздняя покупка, но раньше истекает.
        $newer = $this->subscription($user, [
            'purchased_at' => now()->subDays(2),
            'ends_at' => now()->addDays(10),
        ]);

        // Первичный (приобретён раньше), истекает позже.
        $primary = $this->subscription($user, [
            'purchased_at' => now()->subDays(20),
            'ends_at' => now()->addDays(30),
        ]);

        $found = $this->service->findUsableForUser($user, SubscriptionType::Group);

        $this->assertNotNull($found);
        $this->assertSame($primary->id, $found->id);
    }

    public function test_return_session_restores_used_count(): void
    {
        $user = $this->user();
        $sub = $this->subscription($user, ['sessions_used' => 2]);
        $sub->usages()->create(['used_at' => now()->subDay(), 'description' => 'first']);
        $sub->usages()->create(['used_at' => now(), 'description' => 'second']);

        $updated = $this->service->returnSession($sub, 'Клиент заболел');

        $this->assertSame(1, $updated->sessions_used);
        $this->assertSame(1, $sub->usages()->count());
        $this->assertStringContainsString('Клиент заболел', (string) $updated->admin_note);
    }

    public function test_return_session_without_used_throws(): void
    {
        $user = $this->user();
        $sub = $this->subscription($user, ['sessions_used' => 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->returnSession($sub);
    }
}
