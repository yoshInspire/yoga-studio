<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\SubscriptionService;
use App\Support\PaymentReceiptBuilder;
use App\Support\PurchaseCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;
use YooKassa\Model\Metadata;
use YooKassa\Model\MonetaryAmount;
use YooKassa\Model\Payment\PaymentInterface;
use YooKassa\Model\Payment\PaymentStatus as YooPaymentStatus;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_catalog_contains_online_products(): void
    {
        $products = PurchaseCatalog::onlineProducts();

        $this->assertArrayHasKey('group_4', $products);
        $this->assertSame(6000, $products['group_4']['price']);
        $this->assertSame(SubscriptionType::Group, $products['group_4']['type']);
    }

    public function test_create_from_purchase_builds_subscription_with_validity(): void
    {
        $user = User::create([
            'first_name' => 'Анна',
            'last_name' => 'Смирнова',
            'phone' => '+79991112233',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $subscription = app(SubscriptionService::class)->createFromPurchase(
            $user,
            SubscriptionType::Group,
            4,
            now()->startOfDay(),
            now(),
            30,
            'test payment',
        );

        $this->assertSame(4, $subscription->sessions_total);
        $this->assertTrue($subscription->starts_at->equalTo(now()->startOfDay()));
        $this->assertTrue($subscription->ends_at->equalTo(now()->startOfDay()->addDays(29)));
    }

    public function test_purchase_page_requires_auth(): void
    {
        $this->get(route('purchase.index'))->assertRedirect(route('login'));
    }

    public function test_client_can_open_purchase_page(): void
    {
        $user = User::create([
            'first_name' => 'Анна',
            'last_name' => 'Смирнова',
            'phone' => '+79991112234',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $this->actingAs($user)
            ->get(route('purchase.index'))
            ->assertOk()
            ->assertSee('Купить абонемент')
            ->assertSee('Абонемент · 4 занятия');
    }

    public function test_fulfill_is_idempotent(): void
    {
        $user = User::create([
            'first_name' => 'Анна',
            'last_name' => 'Смирнова',
            'phone' => '+79991112235',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'product_key' => 'group_4',
            'amount' => 6000,
            'currency' => 'RUB',
            'status' => PaymentStatus::Succeeded,
            'starts_at' => now(),
            'description' => 'Абонемент · 4 занятия',
            'idempotence_key' => '11111111-1111-1111-1111-111111111111',
            'paid_at' => now(),
        ]);

        // Metadata и MonetaryAmount — настоящие из SDK: у getMetadata() и
        // getAmount() строгие типы возврата, и анонимная заглушка падала на
        // них раньше, чем тест доходил до проверяемой логики.
        $metadata = new Metadata;
        $metadata->offsetSet('payment_id', (string) $payment->id);

        $remote = Mockery::mock(PaymentInterface::class);
        $remote->shouldReceive('getStatus')->andReturn(YooPaymentStatus::SUCCEEDED);
        $remote->shouldReceive('getMetadata')->andReturn($metadata);
        $remote->shouldReceive('getAmount')->andReturn(
            new MonetaryAmount(number_format($payment->amount, 2, '.', ''), $payment->currency),
        );

        $service = app(PaymentService::class);
        $first = $service->fulfill($payment, $remote);
        $second = $service->fulfill($payment->fresh(), $remote);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'subscription_id' => $first->id,
        ]);
    }

    public function test_signed_return_url_is_absolute(): void
    {
        $user = User::create([
            'first_name' => 'Анна',
            'last_name' => 'Смирнова',
            'phone' => '+79991112236',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'product_key' => 'group_4',
            'amount' => 6000,
            'currency' => 'RUB',
            'status' => PaymentStatus::Pending,
            'starts_at' => now(),
            'description' => 'Абонемент · 4 занятия',
            'idempotence_key' => '22222222-2222-2222-2222-222222222222',
        ]);

        $url = app(PaymentService::class)->signedReturnUrl($payment);

        $this->assertStringStartsWith('http', $url);
        $this->assertStringContainsString('/payments/'.$payment->id.'/return', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_payment_return_works_without_auth_via_signed_url(): void
    {
        URL::forceRootUrl('https://ekoyoga-ik.ru');

        $user = User::create([
            'first_name' => 'Анна',
            'last_name' => 'Смирнова',
            'phone' => '+79991112237',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'product_key' => 'group_4',
            'amount' => 6000,
            'currency' => 'RUB',
            'status' => PaymentStatus::Pending,
            'starts_at' => now(),
            'description' => 'Абонемент · 4 занятия',
            'idempotence_key' => '33333333-3333-3333-3333-333333333333',
        ]);

        $returnUrl = app(PaymentService::class)->signedReturnUrl($payment);

        $this->get($returnUrl)
            ->assertOk()
            ->assertSee('Платёж обрабатывается');
    }

    public function test_payment_return_rejects_unsigned_url(): void
    {
        $user = User::create([
            'first_name' => 'Анна',
            'last_name' => 'Смирнова',
            'phone' => '+79991112238',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'product_key' => 'group_4',
            'amount' => 6000,
            'currency' => 'RUB',
            'status' => PaymentStatus::Pending,
            'starts_at' => now(),
            'description' => 'Абонемент · 4 занятия',
            'idempotence_key' => '44444444-4444-4444-4444-444444444444',
        ]);

        $this->get(route('payments.return', $payment))
            ->assertForbidden();
    }
}
