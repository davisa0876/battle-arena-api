<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Combat catalog: moves, type effectiveness, and learnsets.
 * Additive — does not alter existing creature catalog rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moves', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64)->default('pokeapi');
            $table->unsignedBigInteger('external_id')->nullable();
            $table->string('slug', 140)->unique();
            $table->string('name', 120);
            $table->unsignedSmallInteger('power')->nullable();
            $table->unsignedTinyInteger('accuracy')->nullable();
            $table->unsignedSmallInteger('pp');
            $table->string('damage_class', 32);
            $table->string('type_slug', 64);
            $table->smallInteger('priority')->default(0);
            $table->string('version_group', 64)->nullable();
            $table->boolean('is_mvp_supported')->default(false);
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['is_mvp_supported', 'damage_class']);
            $table->index('type_slug');
        });

        Schema::create('type_effectiveness', function (Blueprint $table) {
            $table->id();
            $table->string('ruleset', 64);
            $table->string('attacking_type_slug', 64);
            $table->string('defending_type_slug', 64);
            $table->decimal('multiplier', 4, 2);
            $table->timestamps();

            $table->unique(['ruleset', 'attacking_type_slug', 'defending_type_slug'], 'type_effectiveness_unique');
            $table->index(['ruleset', 'attacking_type_slug']);
        });

        Schema::create('creature_learnable_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creature_id')->constrained('creatures')->cascadeOnDelete();
            $table->foreignId('move_id')->constrained('moves')->cascadeOnDelete();
            $table->string('version_group', 64);
            $table->unsignedSmallInteger('learn_level')->nullable();
            $table->string('learn_method', 64)->nullable();
            $table->timestamps();

            $table->unique(['creature_id', 'move_id', 'version_group'], 'creature_learnable_moves_unique');
            $table->index(['move_id', 'version_group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creature_learnable_moves');
        Schema::dropIfExists('type_effectiveness');
        Schema::dropIfExists('moves');
    }
};
