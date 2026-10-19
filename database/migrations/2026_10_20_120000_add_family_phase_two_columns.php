<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Семейные группы, фаза 2 (docs/FAMILY_GROUPS.md, раздел 16).
 *
 *  - `subscription_shares.sessions_limit` — владелец открыл абонемент «не
 *    больше N занятий» на весь срок; null — без ограничений;
 *  - `bookings.booked_by_id` — кто записал, если записал не сам идущий
 *    (запись по-прежнему на того, кто идёт: `user_id`);
 *  - `payments.recipient_id` — абонемент куплен в подарок члену семьи: платит
 *    и получает чек `user_id`, абонемент выдаётся получателю;
 *  - `users.guardian_id` — профиль ребёнка без своего входа, им управляет
 *    представитель; уведомления ребёнку уходят представителю.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_shares', function (Blueprint $table) {
            $table->unsignedSmallInteger('sessions_limit')->nullable()->after('user_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('booked_by_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('recipient_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('guardian_id')->nullable()->after('role')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guardian_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booked_by_id');
        });

        Schema::table('subscription_shares', function (Blueprint $table) {
            $table->dropColumn('sessions_limit');
        });
    }
};
