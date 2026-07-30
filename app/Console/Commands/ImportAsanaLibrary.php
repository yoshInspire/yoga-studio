<?php

namespace App\Console\Commands;

use App\Models\Asana;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportAsanaLibrary extends Command
{
    protected $signature = 'asanas:import-library
        {--source=asanas : Папка внутри storage/app с manifest.json и картинками}
        {--force : Перезаписать картинки, которые уже перенесены}';

    protected $description = 'Перенести библиотеку схематичных асан в public/ и заполнить таблицу asanas';

    /** Куда складываем библиотеку относительно public/. */
    private const TARGET_DIR = 'images/asanas/library';

    public function handle(): int
    {
        $sourceRoot = storage_path('app/'.trim((string) $this->option('source'), '/'));
        $manifestPath = $sourceRoot.'/manifest.json';

        if (! File::exists($manifestPath)) {
            $this->error('Не найден манифест: '.$manifestPath);

            return self::FAILURE;
        }

        $manifest = json_decode(File::get($manifestPath), true);

        if (! is_array($manifest) || $manifest === []) {
            $this->error('Манифест пуст или повреждён.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $created = 0;
        $updated = 0;
        $copied = 0;
        $missing = 0;

        foreach ($manifest as $entry) {
            $name = trim((string) ($entry['name'] ?? ''));
            $category = trim((string) ($entry['category'] ?? ''));
            $relative = (string) ($entry['file'] ?? '');

            if ($name === '' || $relative === '') {
                continue;
            }

            $sourceFile = $sourceRoot.'/'.$relative;

            if (! File::exists($sourceFile)) {
                $this->warn('  нет файла: '.$relative);
                $missing++;

                continue;
            }

            $targetRelative = $this->targetPath($category, $name);
            $targetFile = public_path($targetRelative);

            if ($force || ! File::exists($targetFile)) {
                File::ensureDirectoryExists(dirname($targetFile));
                File::copy($sourceFile, $targetFile);
                $copied++;
            }

            $asana = Asana::query()->firstOrNew([
                'name' => $name,
                'category' => $category ?: null,
                'is_custom' => false,
            ]);

            $existed = $asana->exists;
            $asana->image_path = $targetRelative;
            $asana->save();

            $existed ? $updated++ : $created++;
        }

        $this->info(sprintf(
            'Библиотека асан: добавлено %d, обновлено %d, файлов скопировано %d%s.',
            $created,
            $updated,
            $copied,
            $missing > 0 ? ", пропущено без файла {$missing}" : '',
        ));

        return self::SUCCESS;
    }

    /** Латинский путь, чтобы ссылки на картинки не зависели от кириллицы. */
    private function targetPath(string $category, string $name): string
    {
        $categorySlug = Str::slug($category, '-', 'ru') ?: 'bez-kategorii';
        $nameSlug = Str::slug($name, '-', 'ru') ?: Str::lower(Str::random(8));

        return self::TARGET_DIR.'/'.$categorySlug.'/'.$nameSlug.'.png';
    }
}
