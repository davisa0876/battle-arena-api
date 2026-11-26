<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5B.4 — additive deadline + terminal metadata for forfeit/timeout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pvp_battles', function (Blueprint $table) {
            $table->timestamp('action_deadline_at')->nullable()->after('finished_at');
            $table->foreignId('deadline_actor_user_id')->nullable()->after('action_deadline_at')
                ->constrained('users')->nullOnDelete();
            $table->string('deadline_phase', 64)->nullable()->after('deadline_actor_user_id');
            $table->unsignedBigInteger('deadline_version')->nullable()->after('deadline_phase');
            $table->foreignId('forfeiting_user_id')->nullable()->after('loser_user_id')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('timed_out_user_id')->nullable()->after('forfeiting_user_id')
                ->constrained('users')->nullOnDelete();

            $table->index(['status', 'action_deadline_at'], 'pvp_battles_status_deadline_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pvp_battles', function (Blueprint $table) {
            $table->dropIndex('pvp_battles_status_deadline_idx');
            $table->dropConstrainedForeignId('timed_out_user_id');
            $table->dropConstrainedForeignId('forfeiting_user_id');
            $table->dropConstrainedForeignId('deadline_actor_user_id');
            $table->dropColumn([
                'action_deadline_at',
                'deadline_phase',
                'deadline_version',
            ]);
        });
    }
};
