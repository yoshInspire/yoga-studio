<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Разделы библиотеки поз. Раньше их набор просто выводился из того, какие
 * значения встречались у асан, поэтому свой раздел создать было нельзя.
 *
 * Название раздела остаётся у позы в поле category — так все существующие
 * выборки продолжают работать, — а эта таблица задаёт список разделов
 * и их порядок. Синхронизацию держит AsanaProgramService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asana_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Переносим разделы, которые приехали вместе с библиотекой.
        $existing = DB::table('asanas')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $now = now();
        $sort = 0;
        $rows = [];

        foreach ($existing as $name) {
            $rows[] = [
                'name' => $name,
                'sort' => $sort++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('asana_categories')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asana_categories');
    }
};
