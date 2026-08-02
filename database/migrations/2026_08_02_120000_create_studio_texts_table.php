<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тексты студии, которые администратор правит сам.
 * Значение по умолчанию живёт в коде — здесь только переопределения.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_texts', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_texts');
    }
};
