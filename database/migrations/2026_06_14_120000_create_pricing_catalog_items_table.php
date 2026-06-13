<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('product_key')->unique();
            $table->string('name');
            $table->string('category');
            $table->string('subscription_type');
            $table->unsignedSmallInteger('sessions')->default(1);
            $table->unsignedInteger('price');
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->boolean('online')->default(false);
            $table->boolean('active')->default(true);
            $table->string('section_title')->nullable();
            $table->boolean('highlight')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_catalog_items');
    }
};
