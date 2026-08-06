<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\PaymentReceiptBuilder;
use App\Support\PurchaseCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;
use YooKassa\Model\Metadata;
use YooKassa\Model\MonetaryAmount;
use YooKassa\Model\Payment\PaymentInterface;
use YooKassa\Model\Payment\PaymentStatus as YooPaymentStatus;

/**
 * Покупка нескольких абонементов одним платежом.
 *
 * Держать несколько абонементов система умела и раньше — SubscriptionService
 * списывает из купленного раньше. Новое здесь только то, что их можно взять
 * одним платежом, и на каждый выдаётся свой абонемент.
 */
class MultiProductPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $phone = '+79995550001'): User
    {
        return User::create([
            'first_name' => 'Анна',
            'last_name' => 'Смирнова',
            'phone' => $phone,
            'email' => 'anna@example.com',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);
    }

    /**
     * Заглушка ответа ЮKassa на уже созданный платёж.
     *
     * Metadata и MonetaryAmount берём настоящие из SDK, а не анонимные классы:
     * у `getMetadata()` и `getAmount()` строгие типы возврата, и подделка
     * падает на них ещё до того, как дойдёт до проверяемой логики.
     */
    private function remoteFor(Payment $payment): PaymentInterface
    {
        $metadata = new Metadata;
        $metadata->offsetSet('payment_id', (string) $payment->id);

        $remote = Mockery::mock(PaymentInterface::class);
        $remote->shouldReceive('getStatus')->andReturn(YooPaymentStatus::SUCCEEDED);
        $remote->shouldReceive('getMetadata')->andReturn($metadata);
        $remote->shouldReceive('getAmount')->andReturn(
            new MonetaryAmount(number_format($payment->amount, 2, '.', ''), $payment->currency),
        );

        return $remote;
    }

    public function test_receipt_lists_every_product_and_sums_to_the_payment(): void
    {
        $user = $this->client();
        $products = [PurchaseCatalog::find('group_4'), PurchaseCatalog::find('group_8')];

        $receipt = PaymentReceiptBuilder::build($user, $products);

        $this->assertCount(2, $receipt['items']);
        $this->assertSame('Абонемент · 4 занятия', $receipt['items'][0]['description']);

        // Сумма позиций чека обязана совпасть с суммой платежа, иначе
        // ЮKassa отклонит чек по 54-ФЗ.
        $receiptTotal = array_sum(array_map(
            fn (array $i) => (float) $i['amount']['value'],
            $receipt['items'],
        ));
        $this->assertSame((float) (6000 + 10400), $receiptTotal);
    }

    public function test_each_item_produces_its_own_subscription(): void
    {
        $user = $this->client('+79995550002');

        $payment = Payment::create([
            'user_id' => $user->id,
            'product_key' => 'group_4',
            'amount' => 6000 + 13200,
            'currency' => 'RUB',
            'status' => PaymentStatus::Succeeded,
            'starts_at' => now()->startOfDay(),
            'description' => 'Абонемент · 4 занятия и ещё 1 тариф',
            'idempotence_key' => '22222222-2222-2222-2222-222222222222',
        ]);

        foreach (['group_4', 'individual_4'] as $key) {
            $product = PurchaseCatalog::find($key);
            $payment->items()->create([
                'product_key' => $key,
                'name' => $product['name'],
                'type' => $product['type'],
                'price' => $product['price'],
                'sessions' => $product['sessions'],
                'validity_days' => $product['validity_days'],
            ]);
        }

        app(PaymentService::class)->fulfill($payment, $this->remoteFor($payment));

        $subscriptions = $user->subscriptions()->orderBy('id')->get();
        $this->assertCount(2, $subscriptions);
        $this->assertSame(SubscriptionType::Group, $subscriptions[0]->type);
        $this->assertSame(SubscriptionType::Individual, $subscriptions[1]->type);

        // Каждая позиция знает свой абонемент, а платёж ссылается на первый.
        $this->assertSame(
            $subscriptions->pluck('id')->all(),
            $payment->items()->orderBy('id')->pluck('subscription_id')->all(),
        );
        $this->assertSame($subscriptions[0]->id, $payment->fresh()->subscription_id);
    }

    public function test_fulfilling_twice_does_not_double_the_subscriptions(): void
    {
        $user = $this->client('+79995550003');

        $payment = Payment::create([
            'user_id' => $user->id,
            'product_key' => 'group_4',
            'amount' => 6000 + 10400,
            'currency' => 'RUB',
            'status' => PaymentStatus::Succeeded,
            'starts_at' => now()->startOfDay(),
            'description' => 'Заказ',
            'idempotence_key' => '33333333-3333-3333-3333-333333333333',
        ]);

        foreach (['group_4', 'group_8'] as $key) {
            $product = PurchaseCatalog::find($key);
            $payment->items()->create([
                'product_key' => $key,
                'name' => $product['name'],
                'type' => $product['type'],
                'price' => $product['price'],
                'sessions' => $product['sessions'],
                'validity_days' => $product['validity_days'],
            ]);
        }

        $service = app(PaymentService::class);
        $service->fulfill($payment, $this->remoteFor($payment));
        $service->fulfill($payment->fresh(), $this->remoteFor($payment));

        $this->assertSame(2, $user->subscriptions()->count());
    }

    public function test_legacy_payment_without_items_is_still_fulfilled(): void
    {
        // Клиент открыл оплату до выката, а завершил после: состава у платежа
        // нет, но абонемент выдать обязаны.
        $user = $this->client('+79995550004');

        $payment = Payment::create([
            'user_id' => $user->id,
            'product_key' => 'group_8',
            'amount' => 10400,
            'currency' => 'RUB',
            'status' => PaymentStatus::Succeeded,
            'starts_at' => now()->startOfDay(),
            'description' => 'Абонемент · 8 занятий',
            'idempotence_key' => '44444444-4444-4444-4444-444444444444',
        ]);
        $payment->items()->delete();

        $subscription = app(PaymentService::class)->fulfill($payment, $this->remoteFor($payment));

        $this->assertSame(8, $subscription->sessions_total);
        $this->assertSame(1, $user->subscriptions()->count());
        // Состав достроен, чтобы дальше всё шло одним путём.
        $this->assertSame(1, $payment->items()->count());
        $this->assertSame(10400, $payment->items()->first()->price);
    }

    public function test_repeated_key_in_one_order_is_collapsed(): void
    {
        // Количеств у абонементов нет: два одинаковых тарифа в заказе —
        // ошибка ввода, а не намерение купить два.
        $user = $this->client('+79995550005');
        $service = app(PaymentService::class);

        $reflection = new \ReflectionMethod($service, 'orderDescription');
        $products = [PurchaseCatalog::find('group_4')];
        $this->assertSame('Абонемент · 4 занятия', $reflection->invoke($service, $products));

        $products[] = PurchaseCatalog::find('group_8');
        $this->assertSame('Абонемент · 4 занятия и ещё 1 тариф', $reflection->invoke($service, $products));

        $products[] = PurchaseCatalog::find('individual_4');
        $this->assertSame('Абонемент · 4 занятия и ещё 2 тарифа', $reflection->invoke($service, $products));
    }

    public function test_api_accepts_a_list_of_products(): void
    {
        $user = $this->client('+79995550006');

        // ЮKassa не настроена в тестах — до сети не дойдём, но валидация
        // и разбор списка отработают, и это то, что здесь проверяется.
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchase', [
            'product_keys' => ['group_4', 'group_8'],
            'starts_at' => now()->toDateString(),
        ]);

        $this->assertContains($response->status(), [422, 502]);
        $this->assertStringNotContainsString('product_keys', (string) $response->json('message'));
    }

    public function test_api_rejects_an_empty_selection(): void
    {
        $user = $this->client('+79995550007');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchase', [
            'product_keys' => [],
            'starts_at' => now()->toDateString(),
        ])->assertStatus(422);
    }
}
