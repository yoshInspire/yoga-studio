<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Семейные группы: клиенты по приглашению собираются в семью, и владелец
 * абонемента может открыть его остальным.
 *
 * Делится не семья, а владелец абонемента: членство само по себе ничего не
 * даёт, доступ к занятиям — только строки `subscription_shares`.
 *
 * `family_members.user_id` уникален: один человек — одна семья. Глава семьи
 * лежит здесь же с ролью `owner`, а `family_groups.owner_id` — его копия для
 * быстрых проверок; меняются они вместе (`FamilyService::transferOwnership`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role', 10);
            $table->timestamp('joined_at');
            $table->timestamps();
        });

        // Приглашение одноразовое. Код один и тот же в ссылке и для ввода
        // руками в приложении: 8 знаков из 32 — около 10^12 вариантов, а
        // перебор режет throttle. Храним открыто, чтобы глава мог переслать
        // неиспользованное приглашение ещё раз.
        Schema::create('family_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 8)->unique();
            $table->timestamp('expires_at');
            $table->foreignId('accepted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['subscription_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_shares');
        Schema::dropIfExists('family_invites');
        Schema::dropIfExists('family_members');
        Schema::dropIfExists('family_groups');
    }
};
