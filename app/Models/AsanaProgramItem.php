<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

#[Fillable([
    'program_id',
    'asana_id',
    'image_path',
    'note',
    'position',
])]
class AsanaProgramItem extends Model
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(AsanaProgram::class, 'program_id');
    }

    public function asana(): BelongsTo
    {
        return $this->belongsTo(Asana::class, 'asana_id');
    }

    /** Своя зарисовка перекрывает базовую позу из библиотеки. */
    public function effectiveImagePath(): ?string
    {
        return $this->image_path ?: $this->asana?->image_path;
    }

    public function imageUrl(): ?string
    {
        $path = $this->effectiveImagePath();

        return $path === null ? null : '/'.ltrim($path, '/');
    }

    public function title(): string
    {
        return $this->asana?->name ?: 'Своя зарисовка';
    }

    /**
     * Панорамная картинка — например, лист «Сурья намаскар» с целой
     * последовательностью. В узкой колонке она сжимается в нечитаемую полоску,
     * поэтому при печати такие занимают всю ширину строки.
     */
    public function isWideImage(): bool
    {
        return $this->aspectRatio() >= 2.2;
    }

    /** Соотношение ширины к высоте; 0, если размер определить не удалось. */
    public function aspectRatio(): float
    {
        $path = $this->effectiveImagePath();

        if (blank($path)) {
            return 0.0;
        }

        // Файлы неизменяемы: библиотека приезжает с кодом, своя зарисовка
        // каждый раз получает новое имя. Значит кэшировать можно по пути.
        return Cache::rememberForever('asana-ratio:'.$path, function () use ($path): float {
            $absolute = public_path($path);

            if (! is_file($absolute)) {
                return 0.0;
            }

            $size = @getimagesize($absolute);

            if ($size === false || empty($size[1])) {
                return 0.0;
            }

            return round($size[0] / $size[1], 3);
        });
    }

    public function isEdited(): bool
    {
        return filled($this->image_path) && $this->asana_id !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
