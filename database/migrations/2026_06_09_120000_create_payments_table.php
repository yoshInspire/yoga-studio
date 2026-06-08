<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_key', 64);
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('RUB');
            $table->string('yookassa_payment_id', 64)->nullable()->unique();
            $table->string('status', 32);
            $table->date('starts_at');
            $table->string('description');
            $table->string('confirmation_url', 2048)->nullable();
            $table->uuid('idempotence_key')->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
