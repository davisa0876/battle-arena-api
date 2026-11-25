<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owned_creatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creature_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('level')->default(1);
            $table->unsignedBigInteger('total_xp')->default(0);
            $table->boolean('is_active')->default(false);
            // Mirrors user_id while the creature is active, otherwise NULL. A UNIQUE index on this
            // column enforces "at most one active creature per user" on both SQLite and PostgreSQL
            // (NULLs never collide). Maintained by the OwnedCreature model and OwnedCreatureService.
            $table->unsignedBigInteger('active_user_id')->nullable()->unique();
            $table->timestamp('acquired_at');
            $table->timestamps();

            $table->unique(['user_id', 'creature_id']);
        });

        Schema::create('creature_evolution_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owned_creature_id')->constrained('owned_creatures')->cascadeOnDelete();
            $table->foreignId('from_creature_id')->constrained('creatures')->restrictOnDelete();
            $table->foreignId('to_creature_id')->constrained('creatures')->restrictOnDelete();
            $table->timestamp('evolved_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creature_evolution_history');
        Schema::dropIfExists('owned_creatures');
    }
};
