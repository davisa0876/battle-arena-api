<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5B.2 — creature selection, durable PvP battles, combatants, actions, events, rewards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pvp_rooms', function (Blueprint $table) {
            // status values extended in application: waiting_for_guest|ready|in_battle|closed
            // closed_reason may be battle_completed
        });

        Schema::create('pvp_battle_selections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('room_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('owned_creature_id')->constrained('owned_creatures')->restrictOnDelete();
            $table->boolean('is_locked')->default(false);
            $table->timestamp('selected_at');
            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('room_id')->references('id')->on('pvp_rooms')->cascadeOnDelete();
            $table->unique(['room_id', 'user_id']);
            $table->index(['user_id', 'is_locked']);
        });

        Schema::create('pvp_battles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('room_id');
            $table->string('status', 32);
            $table->string('phase', 64);
            $table->unsignedInteger('turn_number')->default(1);
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('host_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('guest_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('current_attacker_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('current_defender_user_id')->constrained('users')->restrictOnDelete();
            $table->json('pending_attack_snapshot')->nullable();
            $table->string('rng_seed', 64);
            $table->unsignedInteger('rng_cursor')->default(0);
            $table->string('rules_version', 64);
            $table->json('rules_snapshot');
            $table->json('reward_snapshot');
            $table->foreignId('winner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('loser_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason', 32)->nullable();
            $table->boolean('rewards_processed')->default(false);
            $table->unsignedInteger('last_event_sequence')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('room_id')->references('id')->on('pvp_rooms')->restrictOnDelete();
            $table->unique('room_id');
            $table->index(['status', 'phase']);
            $table->index(['host_user_id', 'status']);
            $table->index(['guest_user_id', 'status']);
        });

        Schema::create('pvp_battle_combatants', function (Blueprint $table) {
            $table->id();
            $table->uuid('battle_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
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

            $table->foreign('battle_id')->references('id')->on('pvp_battles')->cascadeOnDelete();
            $table->unique(['battle_id', 'side']);
            $table->unique(['battle_id', 'user_id']);
        });

        Schema::create('pvp_battle_combatant_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pvp_battle_combatant_id')->constrained('pvp_battle_combatants')->cascadeOnDelete();
            $table->foreignId('move_id')->nullable()->constrained('moves')->nullOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->unsignedSmallInteger('pp_current');
            $table->unsignedSmallInteger('pp_max');
            $table->unsignedSmallInteger('stamina_cost');
            $table->json('move_snapshot');
            $table->timestamps();

            $table->unique(['pvp_battle_combatant_id', 'slot']);
        });

        Schema::create('pvp_battle_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('battle_id');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action_type', 32);
            $table->string('idempotency_key', 120);
            $table->unsignedBigInteger('expected_version');
            $table->unsignedBigInteger('resolved_version')->nullable();
            $table->json('input_snapshot');
            $table->json('result_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('battle_id')->references('id')->on('pvp_battles')->cascadeOnDelete();
            $table->unique(['battle_id', 'actor_user_id', 'idempotency_key']);
            $table->index(['battle_id', 'created_at']);
        });

        Schema::create('pvp_battle_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('battle_id');
            $table->unsignedBigInteger('sequence');
            $table->string('type', 64);
            $table->string('actor', 16)->nullable();
            $table->string('target', 16)->nullable();
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('battle_id')->references('id')->on('pvp_battles')->cascadeOnDelete();
            $table->unique(['battle_id', 'sequence']);
            $table->index(['battle_id', 'sequence']);
        });

        Schema::create('pvp_battle_rewards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('battle_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('outcome', 16);
            $table->unsignedInteger('xp_awarded');
            $table->unsignedInteger('trainer_levels_gained')->default(0);
            $table->string('idempotency_key', 191);
            $table->timestamps();

            $table->foreign('battle_id')->references('id')->on('pvp_battles')->cascadeOnDelete();
            $table->unique(['battle_id', 'user_id']);
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pvp_battle_rewards');
        Schema::dropIfExists('pvp_battle_events');
        Schema::dropIfExists('pvp_battle_actions');
        Schema::dropIfExists('pvp_battle_combatant_moves');
        Schema::dropIfExists('pvp_battle_combatants');
        Schema::dropIfExists('pvp_battles');
        Schema::dropIfExists('pvp_battle_selections');
    }
};
