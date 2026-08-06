<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Лента уведомлений клиента.
 *
 * До этого всё, что студия сообщала клиенту, уходило только в почту и Telegram
 * и нигде не сохранялось: client_mailing_logs хранит лишь факт отправки
 * (кому, какого типа, в какой день) для защиты от дублей, без текста.
 * Клиент без привязанного Telegram, не читающий почту, не узнавал ни про
 * отмену занятия, ни про кончающийся абонемент.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Тип задаёт иконку и группировку в приложении: reminder, news,
            // session_cancelled, subscription_low и т.д.
            $table->string('type', 40)->default('studio');
            $table->string('title');
            $table->text('body');
            // Куда вести по тапу: {"session_id": 12} / {"news_slug": "..."}.
            $table->json('payload')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Лента: свежие сверху для одного пользователя.
            $table->index(['user_id', 'created_at']);
            // Бейдж: счёт непрочитанных опрашивается часто, ему нужен свой индекс.
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notifications');
    }
};
