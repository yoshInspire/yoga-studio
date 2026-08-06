<?php

namespace App\Support;

use App\Models\User;
use InvalidArgumentException;

class PaymentReceiptBuilder
{
    /**
     * Чек по 54-ФЗ.
     *
     * Позиций может быть несколько — клиент вправе купить сразу пару
     * абонементов. Сумма позиций обязана совпадать с суммой платежа, иначе
     * ЮKassa отклонит чек; за это отвечает вызывающий код, который считает
     * сумму по тому же списку.
     *
     * @param  list<array{name: string, price: int}>  $products
     * @return array<string, mixed>
     */
    public static function build(User $user, array $products): array
    {
        if ($products === []) {
            throw new InvalidArgumentException('Чек не может быть пустым.');
        }

        $customer = self::customer($user);

        $receipt = [
            'customer' => $customer,
            'items' => array_map(fn (array $product) => [
                'description' => mb_substr($product['name'], 0, 128),
                'quantity' => '1.00',
                'amount' => [
                    'value' => number_format($product['price'], 2, '.', ''),
                    'currency' => config('yookassa.currency', 'RUB'),
                ],
                'vat_code' => (int) config('yookassa.vat_code', 1),
                'payment_mode' => 'full_payment',
                'payment_subject' => 'service',
            ], array_values($products)),
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
