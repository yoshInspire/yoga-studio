<?php

/**
 * Standalone check: group/event subscriptions never cross-deduct.
 * Uses in-memory SQLite — safe to run on production server.
 *
 * php scripts/verify_subscription_type_isolation.php
 */

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';
$_SERVER['APP_ENV'] = 'testing';
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = ':memory:';

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

Artisan::call('migrate', ['--force' => true]);

$service = app(BookingService::class);

$user = User::query()->create([
    'first_name' => 'Анна',
    'last_name' => 'Тестова',
    'phone' => '+79990000999',
    'role' => UserRole::Client,
    'password' => 'secret123',
]);

$event = Subscription::query()->create([
    'user_id' => $user->id,
    'type' => SubscriptionType::SpecialEvent,
    'sessions_total' => 1,
    'sessions_used' => 0,
    'purchased_at' => now()->subHours(2),
    'starts_at' => now(),
    'ends_at' => now()->addDays(30),
]);

$group = Subscription::query()->create([
    'user_id' => $user->id,
    'type' => SubscriptionType::Group,
    'sessions_total' => 4,
    'sessions_used' => 0,
    'purchased_at' => now()->subHour(),
    'starts_at' => now(),
    'ends_at' => now()->addDays(30),
]);

$groupSession = ClassSession::query()->create([
    'topic' => 'Групповая йога',
    'starts_at' => now()->addDay()->setTime(10, 0),
    'type' => SubscriptionType::Group,
    'capacity' => 6,
    'status' => ClassSessionStatus::Scheduled,
]);

$eventSession = ClassSession::query()->create([
    'topic' => 'Йога-нидра',
    'starts_at' => now()->addDays(2)->setTime(18, 0),
    'type' => SubscriptionType::SpecialEvent,
    'capacity' => 10,
    'status' => ClassSessionStatus::Scheduled,
]);

$groupBooking = $service->book($user, $groupSession);
assert($groupBooking->subscription_id === $group->id, 'Group class must deduct from group subscription');
assert($group->fresh()->sessions_used === 1, 'Group subscription must be charged');
assert($event->fresh()->sessions_used === 0, 'Event subscription must stay untouched after group booking');

$eventBooking = $service->book($user, $eventSession);
assert($eventBooking->subscription_id === $event->id, 'Event class must deduct from event subscription');
assert($event->fresh()->sessions_used === 1, 'Event subscription must be charged');
assert($group->fresh()->sessions_used === 1, 'Group subscription must not change after event booking');

try {
    $service->book($user, $groupSession, $event);
    fwrite(STDERR, "FAIL: forced event subscription on group class was allowed\n");
    exit(1);
} catch (InvalidArgumentException) {
    // expected
}

echo "OK: subscription type isolation verified (group/event never cross-deduct)\n";
