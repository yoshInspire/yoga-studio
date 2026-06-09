<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('num', 4);
            $table->unsignedSmallInteger('sort_order')->default(0)->index();
            $table->string('title');
            $table->text('lead');
            $table->string('tag')->nullable();
            $table->string('cover_image_path')->nullable();
            $table->json('gallery_paths')->nullable();
            $table->json('body')->nullable();
            $table->json('benefits')->nullable();
            $table->boolean('is_published')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directions');
    }
};
