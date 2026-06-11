<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->timestamps();

            $table->unique(['news_id', 'user_id']);
            $table->index(['news_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_reactions');
    }
};
