<?php

use App\Models\News;
use App\Support\Seo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('title');
        });

        News::query()->orderBy('id')->each(function (News $news): void {
            $news->update([
                'slug' => Seo::uniqueSlug($news->title, 'news', $news->id),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
