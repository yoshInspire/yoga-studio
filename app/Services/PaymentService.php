<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionType;
use App\Models\Payment;
use App\Models\PaymentItem;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PaymentReceiptBuilder;
use App\Support\PurchaseCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use YooKassa\Common\Exceptions\ApiException;
use YooKassa\Model\Notification\AbstractNotification;
use YooKassa\Model\Payment\PaymentInterface;
use YooKassa\Model\Payment\PaymentStatus as YooPaymentStatus;

class PaymentService
{
    public function __construct(
        private YooKassaService $yookassa,
        private SubscriptionService $subscriptions,
        private AdminActivityNotifier $adminActivity,
    ) {}

    /**
     * Одноразовый тариф (пробное занятие) клиенту больше не положен.
     *
     * Пробное — знакомство со студией, поэтому его закрывает любой след
     * прежних занятий: оплаченное пробное, любой абонемент (в том числе
     * выданный администратором вручную — платежа у него нет) и любая запись
     * на занятие, даже отменённая. Иначе клиент со стажем покупает пробное
     * вместо разового, как это вышло с абонементами из админки.
     *
     * Незавершённые платежи не блокируют — клиент мог просто закрыть оплату.
     */
    public static function isAlreadyUsedOnceOnlyProduct(User $user, string $productKey): bool
    {
        if (! PurchaseCatalog::isOncePerClient($productKey)) {
            return false;
        }

        $paidBefore = Payment::query()
            ->where('user_id', $user->id)
            ->where('product_key', $productKey)
            ->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::WaitingForCapture])
            ->exists();

        return $paidBefore || self::hasStudioHistory($user);
    }

    /** Клиент уже не новый: у него есть абонемент или запись на занятие. */
    private static function hasStudioHistory(User $user): bool
    {
        return $user->subscriptions()->exists() || $user->bookings()->exists();
    }

    /**
     * Создать платёж на один или несколько тарифов.
     *
     * Несколько — потому что клиент вправе взять сразу, например, групповой
     * абонемент и индивидуальный: держать два абонемента система умеет давно
     * (SubscriptionService списывает из купленного раньше), а вот оплатить их
     * одним платежом до этого было нельзя.
     *
     * @param  string|list<string>  $productKeys  один ключ или набор разных
     */
    public function initiate(User $user, string|array $productKeys, Carbon $startsAt): Payment
    {
        if (! $this->yookassa->isConfigured()) {
            throw new InvalidArgumentException('Онлайн-оплата временно недоступна. Обратитесь в студию.');
        }

        // Набор, а не список: количеств у абонементов нет, два одинаковых
        // тарифа в одном заказе — это ошибка ввода, а не намерение.
        $keys = array_values(array_unique((array) $productKeys));

        if ($keys === []) {
            throw new InvalidArgumentException('Выберите хотя бы один абонемент.');
        }

        $products = array_map(PurchaseCatalog::find(...), $keys);
        $startsAt = $startsAt->startOfDay();

        foreach ($keys as $i => $key) {
            if (self::isAlreadyUsedOnceOnlyProduct($user, $key)) {
                throw new InvalidArgumentException(
                    '«'.$products[$i]['name'].'» можно приобрести только один раз. Выберите другой тариф.',
                );
            }
        }

        if ($startsAt->lt(now()->startOfDay())) {
            throw new InvalidArgumentException('Дата начала абонемента не может быть в прошлом.');
        }

        if ($startsAt->gt(now()->addMonths(3)->startOfDay())) {
            throw new InvalidArgumentException('Дату начала можно выбрать не более чем на 3 месяца вперёд.');
        }

        $total = array_sum(array_column($products, 'price'));

        $payment = Payment::query()->create([
            'user_id' => $user->id,
            // Для покупки из одной позиции колонка заполняется как раньше —
            // на неё опираются прежние платежи, админка и отчёты.
            'product_key' => $keys[0],
            'amount' => $total,
            'currency' => config('yookassa.currency', 'RUB'),
            'status' => PaymentStatus::Pending,
            'starts_at' => $startsAt,
            'description' => $this->orderDescription($products),
            'idempotence_key' => (string) Str::uuid(),
        ]);

        // PurchaseCatalog::find() возвращает тариф без собственного ключа —
        // берём его из исходного списка по тому же индексу.
        foreach ($products as $i => $product) {
            $payment->items()->create([
                'product_key' => $keys[$i],
                'name' => $product['name'],
                'type' => $product['type'],
                'price' => $product['price'],
                'sessions' => $product['sessions'],
                'validity_days' => $product['validity_days'],
            ]);
        }

        try {
            $response = $this->yookassa->createPayment([
                'amount' => [
                    // Сумма и позиции чека считаются по одному и тому же
                    // списку: если они разойдутся, ЮKassa отклонит чек.
                    'value' => $this->formatAmount($total),
                    'currency' => config('yookassa.currency', 'RUB'),
                ],
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => $this->signedReturnUrl($payment),
                ],
                'capture' => true,
                'description' => $this->paymentDescription($user, $payment->description),
                'metadata' => [
                    'payment_id' => (string) $payment->id,
                    'user_id' => (string) $user->id,
                    'product_key' => $keys[0],
                    'product_keys' => implode(',', $keys),
                ],
                'receipt' => PaymentReceiptBuilder::build($user, $products),
            ], $payment->idempotence_key);
        } catch (ApiException $e) {
            $payment->delete();

            throw new InvalidArgumentException(
                'Не удалось создать платёж: '.$this->humanizeApiError($e),
                previous: $e,
            );
        }

        $payment->update([
            'yookassa_payment_id' => $response->getId(),
            'status' => $this->mapRemoteStatus($response->getStatus()),
            'confirmation_url' => $response->getConfirmation()?->getConfirmationUrl(),
        ]);

        $payment = $payment->refresh();
        $this->adminActivity->clientStartedPurchase($user, $payment);

        return $payment;
    }

    public function signedReturnUrl(Payment $payment): string
    {
        return URL::temporarySignedRoute(
            'payments.return',
            now()->addDays(7),
            ['payment' => $payment],
            absolute: true,
        );
    }

    public function syncFromRemote(Payment $payment): Payment
    {
        if ($payment->yookassa_payment_id === null) {
            return $payment;
        }

        $remote = $this->yookassa->getPayment($payment->yookassa_payment_id);

        if ($remote === null) {
            return $payment;
        }

        $payment->update([
            'status' => $this->mapRemoteStatus($remote->getStatus()),
        ]);

        if ($payment->status->isPaid()) {
            $this->fulfill($payment->refresh(), $remote);
        }

        return $payment->refresh();
    }

    public function handleNotification(AbstractNotification $notification): void
    {
        $remote = $notification->getObject();

        if (! $remote instanceof PaymentInterface) {
            return;
        }

        $payment = Payment::query()
            ->where('yookassa_payment_id', $remote->getId())
            ->first();

        if ($payment === null) {
            return;
        }

        $payment->update([
            'status' => $this->mapRemoteStatus($remote->getStatus()),
        ]);

        if ($payment->status->isPaid()) {
            $this->fulfill($payment->refresh(), $remote);
        }
    }

    public function fulfill(Payment $payment, ?PaymentInterface $remote = null): Subscription
    {
        return DB::transaction(function () use ($payment, $remote) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->subscription_id !== null) {
                return $payment->subscription()->firstOrFail();
            }

            if ($remote === null && $payment->yookassa_payment_id !== null) {
                $remote = $this->yookassa->getPayment($payment->yookassa_payment_id);
            }

            if ($remote === null || $remote->getStatus() !== YooPaymentStatus::SUCCEEDED) {
                throw new InvalidArgumentException('Платёж ещё не подтверждён.');
            }

            $this->assertRemotePaymentMatches($payment, $remote);

            // По абонементу на каждую позицию заказа. Параметры берём из самой
            // позиции, а не из каталога: пока клиент оплачивал, студия могла
            // поменять цену или число занятий в админке — выдать нужно то,
            // за что заплатили.
            $items = $payment->items()->orderBy('id')->get();

            // Платёж, созданный до появления позиций (например, клиент открыл
            // оплату до выката, а завершил после), — достраиваем состав из
            // однотоварных колонок, чтобы выдача не сорвалась.
            if ($items->isEmpty()) {
                $items = collect([$this->backfillItem($payment)]);
            }

            $first = null;

            foreach ($items as $item) {
                $subscription = $this->subscriptions->createFromPurchase(
                    $payment->user,
                    $item->type,
                    $item->sessions,
                    $payment->starts_at,
                    now(),
                    $item->validity_days,
                    'Онлайн-оплата · '.$item->name.' · платёж #'.$payment->id,
                );

                $item->update(['subscription_id' => $subscription->id]);
                $first ??= $subscription;
            }

            if ($first === null) {
                throw new InvalidArgumentException('В платеже нет ни одной позиции.');
            }

            $payment->update([
                // Колонка осталась однозначной: в ней первый абонемент заказа.
                // Полный состав — в payment_items.
                'subscription_id' => $first->id,
                'paid_at' => now(),
                'status' => PaymentStatus::Succeeded,
            ]);

            $this->adminActivity->clientPaidSubscription($payment->user, $payment->refresh(), $first);

            return $first;
        });
    }

    /**
     * Достроить позицию для платежа, созданного до появления payment_items.
     * Цену берём из самого платежа, остальное — из каталога: сумма важнее,
     * по ней сходится чек и проверка совпадения с ЮKassa.
     */
    private function backfillItem(Payment $payment): PaymentItem
    {
        $product = PurchaseCatalog::find($payment->product_key);

        return $payment->items()->create([
            'product_key' => $payment->product_key,
            'name' => $product['name'],
            'type' => $product['type'],
            'price' => $payment->amount,
            'sessions' => $product['sessions'],
            'validity_days' => $product['validity_days'],
        ]);
    }

    /** «Абонемент · 8 занятий» или «Абонемент · 8 занятий и ещё 1 тариф». */
    private function orderDescription(array $products): string
    {
        $first = (string) $products[0]['name'];
        $rest = count($products) - 1;

        if ($rest === 0) {
            return $first;
        }

        return $first.' и ещё '.$rest.' '.$this->tariffWord($rest);
    }

    private function tariffWord(int $n): string
    {
        $mod100 = $n % 100;
        $mod10 = $n % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'тарифов';
        }

        return match (true) {
            $mod10 === 1 => 'тариф',
            $mod10 >= 2 && $mod10 <= 4 => 'тарифа',
            default => 'тарифов',
        };
    }

    private function assertRemotePaymentMatches(Payment $payment, PaymentInterface $remote): void
    {
        $metadata = $remote->getMetadata()?->toArray() ?? [];

        if (($metadata['payment_id'] ?? null) !== (string) $payment->id) {
            throw new InvalidArgumentException('Платёж не прошёл проверку безопасности.');
        }

        $remoteAmount = (int) round((float) $remote->getAmount()->getValue());

        if ($remoteAmount !== $payment->amount) {
            throw new InvalidArgumentException('Сумма платежа не совпадает с заказом.');
        }

        if ($remote->getAmount()->getCurrency() !== $payment->currency) {
            throw new InvalidArgumentException('Валюта платежа не совпадает с заказом.');
        }
    }

    private function paymentDescription(User $user, string $productName): string
    {
        return sprintf(
            '%s · %s',
            $productName,
            $user->fullName(),
        );
    }

    private function formatAmount(int $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function mapRemoteStatus(?string $status): PaymentStatus
    {
        return match ($status) {
            YooPaymentStatus::SUCCEEDED => PaymentStatus::Succeeded,
            YooPaymentStatus::WAITING_FOR_CAPTURE => PaymentStatus::WaitingForCapture,
            YooPaymentStatus::CANCELED => PaymentStatus::Canceled,
            default => PaymentStatus::Pending,
        };
    }

    private function humanizeApiError(ApiException $e): string
    {
        $message = trim($e->getMessage());

        if (str_contains($message, 'Receipt is missing')) {
            return 'не настроена фискализация в ЮKassa. Обратитесь в студию.';
        }

        return $message !== '' ? $message : 'ошибка платёжного сервиса.';
    }
}
