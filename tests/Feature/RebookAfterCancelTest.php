<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RebookAfterCancelTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_rebook_same_session_after_cancelling(): void
    {
        $service = app(BookingService::class);

        $user = User::create([
            'first_name' => 'Иван', 'last_name' => 'Петров',
            'phone' => '+79990000001', 'role' => UserRole::Client, 'password' => 'secret123',
        ]);
        Subscription::create([
            'user_id' => $user->id, 'type' => SubscriptionType::Group,
            'sessions_total' => 4, 'sessions_used' => 0, 'purchased_at' => now(),
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
        $session = ClassSession::create([
            'topic' => 'Хатха-йога', 'starts_at' => now()->addDays(2)->setTime(10, 0),
            'type' => SubscriptionType::Group, 'capacity' => 6, 'status' => ClassSessionStatus::Scheduled,
        ]);

        $this->actingAs($user);
        $booking = $service->book($user, $session);
        $service->cancelByClient($booking);

        $again = $service->book($user->fresh(), $session);
        $this->assertTrue($again->isConfirmed());
    }
}
