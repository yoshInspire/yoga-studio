<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionType;
use App\Models\Payment;
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
    ) {}

    public function initiate(User $user, string $productKey, Carbon $startsAt, ?string $paymentMethod = null): Payment
    {
        if (! $this->yookassa->isConfigured()) {
            throw new InvalidArgumentException('Онлайн-оплата временно недоступна. Обратитесь в студию.');
        }

        $product = PurchaseCatalog::find($productKey);
        $startsAt = $startsAt->startOfDay();

        if ($startsAt->lt(now()->startOfDay())) {
            throw new InvalidArgumentException('Дата начала абонемента не может быть в прошлом.');
        }

        if ($startsAt->gt(now()->addMonths(3)->startOfDay())) {
            throw new InvalidArgumentException('Дату начала можно выбрать не более чем на 3 месяца вперёд.');
        }

        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'product_key' => $productKey,
            'amount' => $product['price'],
            'currency' => config('yookassa.currency', 'RUB'),
            'status' => PaymentStatus::Pending,
            'starts_at' => $startsAt,
            'description' => $product['name'],
            'idempotence_key' => (string) Str::uuid(),
        ]);

        try {
            $payload = [
                'amount' => [
                    'value' => $this->formatAmount($product['price']),
                    'currency' => config('yookassa.currency', 'RUB'),
                ],
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => $this->signedReturnUrl($payment),
                ],
                'capture' => true,
                'description' => $this->paymentDescription($user, $product['name']),
                'metadata' => [
                    'payment_id' => (string) $payment->id,
                    'user_id' => (string) $user->id,
                    'product_key' => $productKey,
                ],
                'receipt' => PaymentReceiptBuilder::build($user, $product),
            ];

            if ($paymentMethod === 'sbp') {
                $payload['payment_method_data'] = ['type' => 'sbp'];
            }

            $response = $this->yookassa->createPayment($payload, $payment->idempotence_key);
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

        return $payment->refresh();
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

            $product = PurchaseCatalog::find($payment->product_key);
            $subscription = $this->subscriptions->createFromPurchase(
                $payment->user,
                $product['type'],
                $product['sessions'],
                $payment->starts_at,
                now(),
                $product['validity_days'],
                'Онлайн-оплата · '.$product['name'].' · платёж #'.$payment->id,
            );

            $payment->update([
                'subscription_id' => $subscription->id,
                'paid_at' => now(),
                'status' => PaymentStatus::Succeeded,
            ]);

            return $subscription;
        });
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

        if (str_contains($message, 'Payment method is not available') || str_contains($message, 'payment_method')) {
            return 'выбранный способ оплаты недоступен. Попробуйте другой или обратитесь в студию.';
        }

        return $message !== '' ? $message : 'ошибка платёжного сервиса.';
    }
}
