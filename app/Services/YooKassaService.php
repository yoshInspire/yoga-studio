<?php

namespace App\Services;

use InvalidArgumentException;
use YooKassa\Client;
use YooKassa\Model\Notification\AbstractNotification;
use YooKassa\Model\Notification\NotificationFactory;
use YooKassa\Model\Payment\PaymentInterface;
use YooKassa\Request\Payments\CreatePaymentResponse;

class YooKassaService
{
    public function __construct(
        private Client $client,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('yookassa.shop_id')) && filled(config('yookassa.secret_key'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPayment(array $payload, string $idempotenceKey): CreatePaymentResponse
    {
        $response = $this->client->createPayment($payload, $idempotenceKey);

        if ($response === null) {
            throw new InvalidArgumentException('Не удалось создать платёж в ЮKassa.');
        }

        return $response;
    }

    public function getPayment(string $paymentId): ?PaymentInterface
    {
        return $this->client->getPaymentInfo($paymentId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseNotification(array $payload): AbstractNotification
    {
        return (new NotificationFactory)->factory($payload);
    }
}
