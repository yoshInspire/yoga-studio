<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Токены устройств для пуш-уведомлений.
 *
 * `provider` хранится явно и не завязан на Expo: сейчас приложение на
 * Expo/React Native и присылает `ExponentPushToken[...]`, после переезда на
 * Flutter приедут токены FCM и APNs. Отправкой заведует App\Support\Push,
 * там под каждый provider свой отправитель — таблицу менять не придётся.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Один токен принадлежит одному устройству и, значит, одному
            // пользователю: если на телефоне сменился аккаунт, строка
            // переезжает к новому владельцу, а не задваивается.
            $table->string('token')->unique();
            $table->string('provider', 16)->default('expo');
            $table->string('platform', 16)->nullable();
            // По нему чистим мёртвые устройства: пуши на них уходят в никуда.
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
