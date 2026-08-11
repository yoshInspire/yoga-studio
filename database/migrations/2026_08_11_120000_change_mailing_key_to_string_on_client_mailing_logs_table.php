<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `mailing_key` был датой — и это молча ломало произвольные оповещения.
 *
 * Ключ произвольной рассылки собирался как `Y-m-d-His`, но колонка типа `date`
 * (плюс каст модели) сводила его обратно к дате: PHP разбирает хвост `-132100`
 * как часовой пояс −13:21. При UNIQUE (user_id, type, mailing_key) второе
 * оповещение за день падало на записи в журнал — уже ПОСЛЕ того, как сообщение
 * ушло первому клиенту.
 *
 * Теперь ключ — строка: для рассылок по расписанию это по-прежнему дата, для
 * произвольного оповещения — дата плюс отпечаток текста, чтобы повтор того же
 * сообщения узнавался, а новое сообщение в тот же день отправлялось.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_mailing_logs', function (Blueprint $table) {
            $table->string('mailing_key', 64)->change();
        });

        // Прежние значения приезжают либо как `2026-08-06`, либо как
        // `2026-08-06 00:00:00` — приводим к одному виду, иначе сравнение
        // с `toDateString()` перестанет находить старые записи.
        DB::table('client_mailing_logs')->update([
            'mailing_key' => DB::raw('substr(mailing_key, 1, 10)'),
        ]);
    }

    public function down(): void
    {
        DB::table('client_mailing_logs')->update([
            'mailing_key' => DB::raw('substr(mailing_key, 1, 10)'),
        ]);

        Schema::table('client_mailing_logs', function (Blueprint $table) {
            $table->date('mailing_key')->change();
        });
    }
};
