<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Переписка клиента со студией.
 *
 * У каждого клиента ровно одна переписка — отсюда уникальный `user_id`.
 * Собеседник со стороны студии не фиксируется: отвечать может любой
 * администратор, и для клиента это всё равно «студия».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Денормализовано ради списка переписок: сортировка по времени
            // последнего сообщения без джойна на messages.
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // Автор. Клиент это или студия — определяется сравнением с
            // conversation.user_id, отдельного поля роли не заводим.
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body')->nullable();
            // Путь к вложению на приватном диске. Наружу файл отдаётся только
            // через маршрут с проверкой прав, прямой ссылки на него нет.
            $table->string('attachment_path')->nullable();
            $table->unsignedSmallInteger('attachment_width')->nullable();
            $table->unsignedSmallInteger('attachment_height')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Лента переписки и подсчёт непрочитанного идут по этой паре.
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
