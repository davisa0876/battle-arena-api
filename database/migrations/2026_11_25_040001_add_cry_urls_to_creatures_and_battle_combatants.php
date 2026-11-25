<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creatures', function (Blueprint $table): void {
            $table->string('cry_latest_url', 512)->nullable()->after('image_ref');
            $table->string('cry_legacy_url', 512)->nullable()->after('cry_latest_url');
        });

        Schema::table('battle_combatants', function (Blueprint $table): void {
            $table->string('cry_latest_url', 512)->nullable()->after('image_ref');
            $table->string('cry_legacy_url', 512)->nullable()->after('cry_latest_url');
        });
    }

    public function down(): void
    {
        Schema::table('battle_combatants', function (Blueprint $table): void {
            $table->dropColumn(['cry_latest_url', 'cry_legacy_url']);
        });

        Schema::table('creatures', function (Blueprint $table): void {
            $table->dropColumn(['cry_latest_url', 'cry_legacy_url']);
        });
    }
};
