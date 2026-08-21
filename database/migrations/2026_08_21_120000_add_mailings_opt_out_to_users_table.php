<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отказ клиента от информационных рассылок студии.
 *
 * Отписка касается только рассылок — анонса недели, произвольного объявления,
 * новости, поздравления и вечернего «завтра занятий нет». Личные письма о
 * собственной записи, отмене занятия, кончающемся абонементе и коды входа
 * уходят по-прежнему: это не рассылка, а часть услуги, за которую человек
 * заплатил, и отписаться от неё нельзя, не перестав быть клиентом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('mailings_opt_out_at')->nullable()->after('offer_accepted_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mailings_opt_out_at');
        });
    }
};
