<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\ImageThumbnailer;
use App\Support\RussianDate;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable([
    'title',
    'slug',
    'excerpt',
    'body',
    'image_path',
    'is_published',
    'published_at',
    'notifications_sent_at',
])]
class News extends Model
{
    protected $table = 'news';

    protected static function booted(): void
    {
        static::saving(function (News $news): void {
            if (blank($news->slug) && filled($news->title)) {
                $news->slug = Seo::uniqueSlug($news->title, 'news', $news->exists ? $news->id : null);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        if (ctype_digit((string) $value)) {
            return static::query()->whereKey($value)->first();
        }

        return static::query()->where('slug', $value)->first();
    }

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'notifications_sent_at' => 'datetime',
        ];
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /**
     * Ссылка на уменьшенную копию — для приложения.
     *
     * Копию делает NewsObserver при сохранении, здесь только смотрим, есть ли
     * она: обработка картинки в обычном запросе на чтение недопустима. Копии
     * нет (маленький оригинал, старая новость до команды `news:thumbnails`) —
     * отдаём оригинал, картинка не должна пропадать из-за этого.
     */
    public function imageThumbUrl(): ?string
    {
        if ($this->image_path === null) {
            return null;
        }

        $thumb = ImageThumbnailer::existing($this->image_path);

        return Storage::disk('public')->url($thumb ?? $this->image_path);
    }

    /**
     * Соотношение сторон снимка (ширина / высота).
     *
     * Нужно приложению, чтобы бокс под фотографию принимал форму самого
     * снимка, а не наоборот. Студия выкладывает кадры из инстаграма — почти
     * всегда квадратные, — и в горизонтальном боксе у них срезало треть кадра
     * вместе с надписью на стене и головами.
     *
     * Размеры читаются с диска, поэтому результат кэшируется: ключ включает
     * время правки файла, так что замена картинки в админке сама сбрасывает
     * старое значение.
     */
    public function imageRatio(): ?float
    {
        if ($this->image_path === null) {
            return null;
        }

        $disk = Storage::disk('public');
        $path = ImageThumbnailer::existing($this->image_path) ?? $this->image_path;

        if (! $disk->exists($path)) {
            return null;
        }

        $key = 'news_ratio:'.md5($path.':'.$disk->lastModified($path));

        return Cache::remember($key, now()->addDays(30), function () use ($disk, $path): ?float {
            $size = @getimagesize($disk->path($path));

            if ($size === false || empty($size[1])) {
                return null;
            }

            return round($size[0] / $size[1], 4);
        });
    }

    public function formattedDate(): string
    {
        return RussianDate::dayMonthYear($this->published_at ?? $this->created_at);
    }

    public function readableExcerpt(): string
    {
        if (filled($this->excerpt)) {
            return $this->excerpt;
        }

        return Str::limit(strip_tags($this->body), 160);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(NewsReaction::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query, ?Carbon $on = null): Builder
    {
        $on ??= now();

        return $query
            ->where('is_published', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $on);
    }
}
