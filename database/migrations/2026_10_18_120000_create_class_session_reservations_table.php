<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индивидуальное занятие, открытое под конкретных клиентов.
 *
 * Нет строк — запись открыта для всех, как раньше. Есть строки — записаться
 * могут только эти клиенты, остальные видят слот занятым. Отдельная таблица,
 * а не колонка: парное занятие открывают сразу под двоих.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_session_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['class_session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_session_reservations');
    }
};
