<?php

use App\Support\PurchaseCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Состав платежа: клиент может купить несколько абонементов за один раз.
 *
 * До этого платёж был строго однотоварным — `payments.product_key` и один
 * `subscription_id`. Эти колонки остаются: по ним живут прежние платежи,
 * админка и отчёты, и для покупки из одной позиции (а это подавляющее
 * большинство) они заполняются как раньше. Позиции пишутся всегда, в том
 * числе для однотоварной покупки, чтобы у выдачи был один путь.
 *
 * Цена и параметры тарифа копируются в строку намеренно: каталог студия
 * правит через админку, и через полгода в отчёте должно остаться то, что
 * человек купил, а не то, что стоит сейчас.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            // Абонемент, выданный по этой позиции. Появляется после оплаты.
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_key', 64);
            $table->string('name');
            $table->string('type', 32);
            $table->unsignedInteger('price');
            $table->unsignedInteger('sessions');
            $table->unsignedInteger('validity_days');
            $table->timestamps();

            $table->index('payment_id');
            // Один и тот же тариф в одном заказе дважды не берём: количеств нет,
            // выбор — это набор разных абонементов.
            $table->unique(['payment_id', 'product_key']);
        });

        $this->backfillExistingPayments();
    }

    /**
     * Достроить состав для платежей, созданных до этой миграции.
     *
     * Иначе выдача сорвалась бы у тех, кто открыл форму оплаты до выката, а
     * оплатил после: `fulfill()` ходит за составом в payment_items.
     * Параметры тарифа берём из каталога, а цену — из самого платежа: каталог
     * с тех пор могли поменять, а сумма зафиксирована в платеже и должна
     * сойтись с той, что подтвердила ЮKassa.
     */
    private function backfillExistingPayments(): void
    {
        DB::table('payments')->orderBy('id')->chunkById(200, function ($payments) {
            $rows = [];

            foreach ($payments as $payment) {
                try {
                    $product = PurchaseCatalog::find($payment->product_key);
                } catch (\Throwable) {
                    // Тариф успели удалить из каталога — восстановить состав
                    // неоткуда. Такой платёж остаётся на запасном пути в
                    // PaymentService::backfillItem() и на глаза администратора.
                    continue;
                }

                $rows[] = [
                    'payment_id' => $payment->id,
                    'subscription_id' => $payment->subscription_id,
                    'product_key' => $payment->product_key,
                    'name' => $product['name'],
                    'type' => $product['type']->value,
                    'price' => $payment->amount,
                    'sessions' => $product['sessions'],
                    'validity_days' => $product['validity_days'],
                    'created_at' => $payment->created_at,
                    'updated_at' => $payment->updated_at,
                ];
            }

            if ($rows !== []) {
                DB::table('payment_items')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_items');
    }
};
