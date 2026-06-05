<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'first_name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('first_name')->nullable()->after('id');
                $table->string('last_name')->nullable()->after('first_name');
                $table->string('patronymic')->nullable()->after('last_name');
                $table->string('phone', 11)->nullable()->unique()->after('patronymic');
                $table->unsignedTinyInteger('birth_day')->nullable()->after('phone');
                $table->unsignedTinyInteger('birth_month')->nullable()->after('birth_day');
                $table->unsignedSmallInteger('birth_year')->nullable()->after('birth_month');
                $table->string('role', 20)->default('client')->index()->after('birth_year');
            });
        }

        if (Schema::hasColumn('users', 'name')) {
            foreach (DB::table('users')->whereNotNull('name')->orderBy('id')->get() as $user) {
                $parts = preg_split('/\s+/u', trim($user->name), 2);
                DB::table('users')->where('id', $user->id)->update([
                    'first_name' => $parts[0] ?? 'Пользователь',
                    'last_name' => $parts[1] ?? '—',
                ]);
            }

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('name')->after('id');
            });

            foreach (DB::table('users')->orderBy('id')->get() as $user) {
                DB::table('users')->where('id', $user->id)->update([
                    'name' => trim("{$user->first_name} {$user->last_name}"),
                ]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn([
                    'first_name',
                    'last_name',
                    'patronymic',
                    'phone',
                    'birth_day',
                    'birth_month',
                    'birth_year',
                    'role',
                ]);
            }

            $table->string('email')->nullable(false)->change();
        });
    }
};
