<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent PvE match state, combatant snapshots, actions, and events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pve_battles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('owned_creature_id')->constrained('owned_creatures')->restrictOnDelete();
            $table->string('status', 32);
            $table->string('phase', 64);
            $table->unsignedInteger('round')->default(1);
            $table->unsignedBigInteger('version')->default(1);
            $table->string('rules_version', 64);
            $table->string('difficulty', 32);
            $table->string('outcome', 32)->nullable();
            $table->string('rng_seed', 64);
            $table->unsignedInteger('rng_cursor')->default(0);
            $table->json('opponent_pending_action')->nullable();
            $table->boolean('reward_processed')->default(false);
            $table->unsignedInteger('xp_awarded')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('battle_combatants', function (Blueprint $table) {
            $table->id();
            $table->uuid('battle_id');
            $table->string('side', 16);
            $table->foreignId('creature_id')->constrained('creatures')->restrictOnDelete();
            $table->foreignId('owned_creature_id')->nullable()->constrained('owned_creatures')->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->string('image_ref', 255)->nullable();
            $table->unsignedSmallInteger('level');
            $table->unsignedInteger('hp_current');
            $table->unsignedInteger('hp_max');
            $table->unsignedInteger('stamina_current');
            $table->unsignedInteger('stamina_max');
            $table->json('stats_snapshot');
            $table->json('types_snapshot');
            $table->timestamps();

            $table->foreign('battle_id')->references('id')->on('pve_battles')->cascadeOnDelete();
            $table->unique(['battle_id', 'side']);
        });

        Schema::create('battle_combatant_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('battle_combatant_id')->constrained('battle_combatants')->cascadeOnDelete();
            $table->foreignId('move_id')->nullable()->constrained('moves')->nullOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->unsignedSmallInteger('pp_current');
            $table->unsignedSmallInteger('pp_max');
            $table->unsignedSmallInteger('stamina_cost');
            $table->json('move_snapshot');
            $table->timestamps();

            $table->unique(['battle_combatant_id', 'slot']);
        });

        Schema::create('battle_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('battle_id');
            $table->string('actor', 16);
            $table->string('action_type', 32);
            $table->string('idempotency_key', 120);
            $table->unsignedBigInteger('expected_version');
            $table->unsignedBigInteger('resolved_version')->nullable();
            $table->json('input_snapshot');
            $table->json('result_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('battle_id')->references('id')->on('pve_battles')->cascadeOnDelete();
            $table->unique(['battle_id', 'idempotency_key']);
            $table->index(['battle_id', 'created_at']);
        });

        Schema::create('battle_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('battle_id');
            $table->unsignedBigInteger('sequence');
            $table->string('type', 64);
            $table->string('actor', 16)->nullable();
            $table->string('target', 16)->nullable();
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('battle_id')->references('id')->on('pve_battles')->cascadeOnDelete();
            $table->unique(['battle_id', 'sequence']);
            $table->index(['battle_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_events');
        Schema::dropIfExists('battle_actions');
        Schema::dropIfExists('battle_combatant_moves');
        Schema::dropIfExists('battle_combatants');
        Schema::dropIfExists('pve_battles');
    }
};
