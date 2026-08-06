<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'subscription_id',
    'product_key',
    'amount',
    'currency',
    'yookassa_payment_id',
    'status',
    'starts_at',
    'description',
    'confirmation_url',
    'idempotence_key',
    'paid_at',
])]
class Payment extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'starts_at' => 'date',
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Состав заказа. Пишется всегда, даже когда куплен один тариф, — чтобы у
     * выдачи абонементов был один путь, а не два похожих.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PaymentItem::class);
    }

    public function formattedAmount(): string
    {
        return number_format($this->amount, 0, '', ' ').' ₽';
    }

    public function isFulfilled(): bool
    {
        return $this->subscription_id !== null;
    }
}
