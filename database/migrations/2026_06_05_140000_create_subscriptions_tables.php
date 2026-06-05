<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedSmallInteger('sessions_total');
            $table->unsignedSmallInteger('sessions_used')->default(0);
            $table->date('purchased_at');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('subscription_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->timestamp('used_at');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_usages');
        Schema::dropIfExists('subscriptions');
    }
};
