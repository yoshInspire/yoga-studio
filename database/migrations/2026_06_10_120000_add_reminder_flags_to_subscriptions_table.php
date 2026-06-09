<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('low_sessions_notified_at')->nullable()->after('admin_note');
            $table->timestamp('expiring_notified_at')->nullable()->after('low_sessions_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['low_sessions_notified_at', 'expiring_notified_at']);
        });
    }
};
