<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('trainer_photo_path')->nullable()->after('role');
            $table->string('trainer_title')->nullable()->after('trainer_photo_path');
            $table->text('trainer_bio')->nullable()->after('trainer_title');
            $table->boolean('show_on_site')->default(false)->after('trainer_bio');
            $table->unsignedSmallInteger('site_sort_order')->default(0)->after('show_on_site');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'trainer_photo_path',
                'trainer_title',
                'trainer_bio',
                'show_on_site',
                'site_sort_order',
            ]);
        });
    }
};
