<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\PurchaseCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialOncePerClientTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::create([
            'first_name' => 'Инна',
            'last_name' => 'Гаук',
            'phone' => '+79853822049',
            'email' => 'innagauk@example.test',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);
    }

    private function paidTrial(User $user, PaymentStatus $status = PaymentStatus::Succeeded): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'product_key' => 'group_trial',
            'amount' => 1400,
            'currency' => 'RUB',
            'status' => $status,
            'starts_at' => now(),
            'description' => 'Пробное занятие',
            'idempotence_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    /** Абонемент, выданный администратором вручную: платежа у него нет. */
    private function manualSubscription(User $user): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now()->subMonth(),
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDays(2),
            'admin_note' => 'Оплата в студии',
        ]);
    }

    private function booking(User $user, BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        $session = ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->subWeek()->setTime(19, 15),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        return Booking::create([
            'user_id' => $user->id,
            'class_session_id' => $session->id,
            'status' => $status,
        ]);
    }

    public function test_trial_is_marked_as_once_per_client(): void
    {
        $this->assertTrue(PurchaseCatalog::isOncePerClient('group_trial'));
        $this->assertFalse(PurchaseCatalog::isOncePerClient('group_4'));
    }

    public function test_paid_trial_blocks_second_trial_purchase(): void
    {
        $user = $this->client();
        $this->paidTrial($user);

        $this->assertTrue(PaymentService::isAlreadyUsedOnceOnlyProduct($user, 'group_trial'));
    }

    public function test_paid_trial_does_not_block_regular_subscriptions(): void
    {
        $user = $this->client();
        $this->paidTrial($user);

        $this->assertFalse(PaymentService::isAlreadyUsedOnceOnlyProduct($user, 'group_4'));
        $this->assertFalse(PaymentService::isAlreadyUsedOnceOnlyProduct($user, 'group_single'));
    }

    public function test_unfinished_payment_does_not_block_trial(): void
    {
        $user = $this->client();
        $this->paidTrial($user, PaymentStatus::Pending);
        $this->paidTrial($user, PaymentStatus::Canceled);

        $this->assertFalse(PaymentService::isAlreadyUsedOnceOnlyProduct($user, 'group_trial'));
    }

    public function test_trial_of_another_client_does_not_block(): void
    {
        $used = $this->client();
        $this->paidTrial($used);

        $fresh = User::create([
            'first_name' => 'Пётр', 'last_name' => 'Сидоров',
            'phone' => '+79990001122', 'role' => UserRole::Client, 'password' => 'secret123',
        ]);

        $this->assertFalse(PaymentService::isAlreadyUsedOnceOnlyProduct($fresh, 'group_trial'));
    }

    /**
     * Случай Суровой: клиент пришёл сразу с абонементом, пробное никогда не
     * покупал — и через полтора месяца занятий купил его как «новый».
     */
    public function test_existing_subscription_blocks_trial(): void
    {
        $user = $this->client();
        $this->manualSubscription($user);

        $this->assertTrue(PaymentService::isAlreadyUsedOnceOnlyProduct($user, 'group_trial'));
    }

    public function test_any_booking_blocks_trial_even_cancelled(): void
    {
        $user = $this->client();
        $this->booking($user, BookingStatus::CancelledByClient);

        $this->assertTrue(PaymentService::isAlreadyUsedOnceOnlyProduct($user, 'group_trial'));
    }

    public function test_studio_history_does_not_block_regular_subscriptions(): void
    {
        $user = $this->client();
        $this->manualSubscription($user);
        $this->booking($user);

        $this->assertFalse(PaymentService::isAlreadyUsedOnceOnlyProduct($user, 'group_4'));
        $this->assertFalse(PaymentService::isAlreadyUsedOnceOnlyProduct($user, 'group_single'));
    }

    public function test_new_client_without_history_still_sees_trial(): void
    {
        $user = $this->client();

        $this->assertFalse(PaymentService::isAlreadyUsedOnceOnlyProduct($user, 'group_trial'));
    }

    public function test_client_with_history_does_not_see_trial_in_catalog(): void
    {
        $user = $this->client();
        $this->manualSubscription($user);

        $keys = collect(PurchaseCatalog::groupedOnlineProductsFor($user))
            ->flatten(1)->pluck('key');

        $this->assertNotContains('group_trial', $keys->all());
        $this->assertContains('group_4', $keys->all());
    }

    public function test_used_trial_is_hidden_from_catalog(): void
    {
        $user = $this->client();

        $before = collect(PurchaseCatalog::groupedOnlineProductsFor($user))
            ->flatten(1)->pluck('key');
        $this->assertContains('group_trial', $before->all());

        $this->paidTrial($user);

        $after = collect(PurchaseCatalog::groupedOnlineProductsFor($user))
            ->flatten(1)->pluck('key');
        $this->assertNotContains('group_trial', $after->all());
        $this->assertContains('group_4', $after->all());
    }
}
