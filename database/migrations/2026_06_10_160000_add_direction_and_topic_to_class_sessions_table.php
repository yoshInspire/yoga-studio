<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->foreignId('direction_id')
                ->nullable()
                ->after('title')
                ->constrained('directions')
                ->nullOnDelete();
            $table->string('topic', 120)->nullable()->after('direction_id');
        });

        foreach (DB::table('class_sessions')->orderBy('id')->get() as $row) {
            DB::table('class_sessions')
                ->where('id', $row->id)
                ->update(['topic' => $row->title]);
        }
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('direction_id');
            $table->dropColumn('topic');
        });
    }
};
