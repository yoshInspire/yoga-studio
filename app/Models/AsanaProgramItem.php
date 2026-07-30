<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
