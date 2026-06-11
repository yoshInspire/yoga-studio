<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->string('product_key')->primary();
            $table->unsignedInteger('price');
            $table->timestamps();
        });

        $rows = collect(config('purchases.products', []))
            ->map(fn (array $product, string $key): array => [
                'product_key' => $key,
                'price' => (int) $product['price'],
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            DB::table('product_prices')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
