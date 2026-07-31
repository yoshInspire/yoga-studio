<?php

namespace Database\Seeders;

use App\Models\Direction;
use Illuminate\Database\Seeder;

class DirectionSeeder extends Seeder
{
    public function run(): void
    {
        $items = config('directions.items', []);

        foreach ($items as $index => $item) {
            // Только создаём недостающие направления. Названия, описания и фото
            // редактируются в админке, и перезапись из конфига на каждом деплое
            // затирала правки администратора.
            Direction::query()->firstOrCreate(
                ['slug' => $item['slug']],
                [
                    'num' => $item['num'],
                    'sort_order' => $index + 1,
                    'title' => $item['title'],
                    'lead' => $item['lead'],
                    'tag' => filled($item['tag'] ?? null) ? $item['tag'] : null,
                    'cover_image_path' => $item['img'] ?? null,
                    'gallery_paths' => $item['gallery'] ?? [],
                    'body' => $item['body'] ?? [],
                    'benefits' => $item['benefits'] ?? [],
                    'is_published' => true,
                ],
            );
        }
    }
}
