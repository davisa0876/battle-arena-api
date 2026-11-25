<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: freezes the match rules at start (spec 7.2 "combat rules cannot change after a match begins")
 * and records the trainer level gain produced by the one-time reward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pve_battles', function (Blueprint $table) {
            $table->json('rules_snapshot')->nullable()->after('rules_version');
            $table->unsignedSmallInteger('trainer_levels_gained')->nullable()->after('xp_awarded');
        });
    }

    public function down(): void
    {
        Schema::table('pve_battles', function (Blueprint $table) {
            $table->dropColumn(['rules_snapshot', 'trainer_levels_gained']);
        });
    }
};
