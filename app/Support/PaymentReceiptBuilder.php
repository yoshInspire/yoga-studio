<?php

namespace App\Support;

use App\Models\User;
use InvalidArgumentException;

class PaymentReceiptBuilder
{
    /**
     * @param  array{name: string, price: int}  $product
     * @return array<string, mixed>
     */
    public static function build(User $user, array $product): array
    {
        $customer = self::customer($user);

        $receipt = [
            'customer' => $customer,
            'items' => [
                [
                    'description' => mb_substr($product['name'], 0, 128),
                    'quantity' => '1.00',
                    'amount' => [
                        'value' => number_format($product['price'], 2, '.', ''),
                        'currency' => config('yookassa.currency', 'RUB'),
                    ],
                    'vat_code' => (int) config('yookassa.vat_code', 1),
                    'payment_mode' => 'full_payment',
                    'payment_subject' => 'service',
                ],
            ],
        ];

        $taxSystemCode = config('yookassa.tax_system_code');

        if ($taxSystemCode !== null && $taxSystemCode !== '') {
            $receipt['tax_system_code'] = (int) $taxSystemCode;
        }

        return $receipt;
    }

    /**
     * @return array<string, string>
     */
    private static function customer(User $user): array
    {
        $customer = [
            'full_name' => $user->fullName(),
        ];

        if ($user->email) {
            $customer['email'] = $user->email;
        }

        $phone = PhoneNormalizer::normalize($user->phone);

        if ($phone !== null) {
            $customer['phone'] = $phone;
        }

        if (! isset($customer['email']) && ! isset($customer['phone'])) {
            throw new InvalidArgumentException('Для оплаты нужен телефон или email в профиле.');
        }

        return $customer;
    }
}
