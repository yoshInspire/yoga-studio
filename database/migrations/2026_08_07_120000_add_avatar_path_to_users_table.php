<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Фотография, которую человек ставит себе сам. Не путать с
            // trainer_photo_path — тот снимок студия выбирает для витрины сайта.
            $table->string('avatar_path')->nullable()->after('patronymic');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
