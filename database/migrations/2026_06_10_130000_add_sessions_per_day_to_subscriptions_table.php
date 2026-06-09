<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedTinyInteger('sessions_per_day')->default(1)->after('sessions_used');
        });

        Schema::table('subscription_usages', function (Blueprint $table) {
            $table->unsignedTinyInteger('sessions_spent')->default(1)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('sessions_per_day');
        });

        Schema::table('subscription_usages', function (Blueprint $table) {
            $table->dropColumn('sessions_spent');
        });
    }
};
