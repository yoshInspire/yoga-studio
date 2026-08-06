<?php

namespace App\Models;

use App\Enums\SubscriptionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция платежа — один купленный тариф.
 *
 * Параметры тарифа скопированы сюда на момент покупки: каталог правится через
 * админку, и отчёт за прошлый месяц должен показывать то, что человек купил,
 * а не то, что стоит сегодня.
 */
#[Fillable([
    'payment_id',
    'subscription_id',
    'product_key',
    'name',
    'type',
    'price',
    'sessions',
    'validity_days',
])]
class PaymentItem extends Model
{
    protected function casts(): array
    {
        return [
            'type' => SubscriptionType::class,
            'price' => 'integer',
            'sessions' => 'integer',
            'validity_days' => 'integer',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** Форма, в которой тариф ждут PurchaseCatalog и PaymentReceiptBuilder. */
    public function toProduct(): array
    {
        return [
            'key' => $this->product_key,
            'name' => $this->name,
            'type' => $this->type,
            'price' => $this->price,
            'sessions' => $this->sessions,
            'validity_days' => $this->validity_days,
        ];
    }
}
