<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creature catalog schema.
 *
 * Model intent: each `creatures` row is a playable variety (concrete stats/types),
 * aligned with provider "pokemon" records rather than abstract species. Future
 * alternate forms can be additional varieties linked via a later species_id column
 * without breaking existing catalog endpoints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creature_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('slug', 64)->unique();
            $table->timestamps();
        });

        Schema::create('creatures', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->unsignedBigInteger('external_id');
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->unsignedInteger('height_cm')->nullable();
            $table->unsignedInteger('weight_g')->nullable();
            $table->unsignedSmallInteger('base_hp');
            $table->unsignedSmallInteger('base_attack');
            $table->unsignedSmallInteger('base_defense');
            $table->unsignedSmallInteger('base_special_attack');
            $table->unsignedSmallInteger('base_special_defense');
            $table->unsignedSmallInteger('base_speed');
            // Optional display reference only. Never store copyrighted artwork blobs.
            $table->string('image_ref', 255)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index('name');
            $table->index(['name', 'id']);
        });

        Schema::create('creature_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creature_id')->constrained('creatures')->cascadeOnDelete();
            $table->foreignId('creature_type_id')->constrained('creature_types')->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->timestamps();

            $table->unique(['creature_id', 'creature_type_id']);
            $table->unique(['creature_id', 'slot']);
            $table->index('creature_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creature_type');
        Schema::dropIfExists('creatures');
        Schema::dropIfExists('creature_types');
    }
};
