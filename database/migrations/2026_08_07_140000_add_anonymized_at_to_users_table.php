<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отметка о том, что учётная запись удалена самим клиентом.
 *
 * Строку пользователя физически не удаляем: на неё ссылаются платежи и чеки,
 * которые оператор обязан хранить пять лет (402-ФЗ, 54-ФЗ), а все внешние
 * ключи стоят на `cascadeOnDelete` — удаление строки снесло бы и их. Поэтому
 * профиль обезличивается, а здесь проставляется дата, чтобы такие записи
 * отличались от живых клиентов в админке и не попадали в рассылки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('offer_accepted_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
