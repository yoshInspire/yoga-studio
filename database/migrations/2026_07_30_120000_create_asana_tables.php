<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Библиотека поз: импортированные схематичные асаны и свои зарисовки.
        Schema::create('asanas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('image_path');
            $table->boolean('is_custom')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['category', 'sort']);
            $table->index('is_custom');
        });

        // Папки занятий с вложенностью: «Растяжка» → «Шпагаты».
        Schema::create('asana_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()
                ->constrained('asana_folders')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['parent_id', 'sort']);
        });

        // Программа занятия — последовательность поз.
        Schema::create('asana_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->nullable()
                ->constrained('asana_folders')->nullOnDelete();
            $table->string('title');
            $table->text('note')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['folder_id', 'sort']);
        });

        // Поза внутри программы. image_path — своя зарисовка или поза,
        // подписанная стилусом: базовая асана при этом остаётся нетронутой.
        Schema::create('asana_program_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')
                ->constrained('asana_programs')->cascadeOnDelete();
            $table->foreignId('asana_id')->nullable()
                ->constrained('asanas')->nullOnDelete();
            $table->string('image_path')->nullable();
            $table->string('note')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['program_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asana_program_items');
        Schema::dropIfExists('asana_programs');
        Schema::dropIfExists('asana_folders');
        Schema::dropIfExists('asanas');
    }
};
