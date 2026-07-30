<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Библиотека схематичных асан едет вместе с кодом: картинки лежат в
 * public/images/asanas/library, описание — в database/data/asanas.json.
 * Так на сервере ничего доливать руками не нужно.
 */
return new class extends Migration
{
    public function up(): void
    {
        $file = database_path('data/asanas.json');

        if (! File::exists($file)) {
            return;
        }

        $rows = json_decode(File::get($file), true);

        if (! is_array($rows) || $rows === []) {
            return;
        }

        $now = now();
        $payload = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $path = trim((string) ($row['image_path'] ?? ''));

            if ($name === '' || $path === '') {
                continue;
            }

            // Повторный прогон не должен плодить дубли.
            $exists = DB::table('asanas')
                ->where('name', $name)
                ->where('image_path', $path)
                ->exists();

            if ($exists) {
                continue;
            }

            $payload[] = [
                'name' => $name,
                'category' => ($row['category'] ?? null) ?: null,
                'image_path' => $path,
                'is_custom' => false,
                'sort' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payload, 100) as $chunk) {
            DB::table('asanas')->insert($chunk);
        }
    }

    public function down(): void
    {
        // Свои зарисовки не трогаем — удаляем только импортированную библиотеку.
        DB::table('asanas')->where('is_custom', false)->delete();
    }
};
