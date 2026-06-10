<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_mailing_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->date('mailing_key');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['user_id', 'type', 'mailing_key']);
            $table->index(['type', 'mailing_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_mailing_logs');
    }
};
