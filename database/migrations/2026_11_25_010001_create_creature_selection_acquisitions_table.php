<?php

use App\Domain\Progression\TrainerProgressionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Durable selection ledger: records the ORIGINAL evolution_stage that consumed
 * an entitlement, so later evolution cannot restore a selection slot.
 *
 * Also recalculates denormalized trainer levels under the arithmetic XP curve
 * without changing total_xp history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creature_selection_acquisitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owned_creature_id')->constrained('owned_creatures')->cascadeOnDelete();
            $table->foreignId('creature_id')->constrained('creatures')->restrictOnDelete();
            $table->unsignedTinyInteger('original_evolution_stage');
            $table->string('source', 64);
            $table->unsignedInteger('trainer_level_at_acquisition');
            $table->string('idempotency_key', 191)->unique();
            $table->timestamps();

            $table->unique('owned_creature_id');
            $table->index(['user_id', 'original_evolution_stage']);
        });

        // Existing owned creatures are Phase-3 starters (stage-1 base forms).
        // Seed ledger rows so historical ownership still consumes stage-1 entitlement.
        $rows = DB::table('owned_creatures as oc')
            ->join('creatures as c', 'c.id', '=', 'oc.creature_id')
            ->leftJoin('player_progress as pp', 'pp.user_id', '=', 'oc.user_id')
            ->select([
                'oc.id as owned_creature_id',
                'oc.user_id',
                'oc.creature_id',
                'c.evolution_stage',
                'pp.level as trainer_level',
                'oc.created_at',
                'oc.updated_at',
            ])
            ->orderBy('oc.id')
            ->get();

        foreach ($rows as $row) {
            DB::table('creature_selection_acquisitions')->insert([
                'user_id' => $row->user_id,
                'owned_creature_id' => $row->owned_creature_id,
                'creature_id' => $row->creature_id,
                'original_evolution_stage' => max(1, (int) $row->evolution_stage),
                'source' => 'legacy_backfill',
                'trainer_level_at_acquisition' => max(1, (int) ($row->trainer_level ?? 1)),
                'idempotency_key' => 'legacy-owned-'.$row->owned_creature_id,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }

        // Preserve total_xp; recalculate derived level with the new arithmetic curve.
        $progression = app(TrainerProgressionService::class);
        DB::table('player_progress')->orderBy('id')->chunkById(200, function ($chunk) use ($progression): void {
            foreach ($chunk as $progress) {
                $level = $progression->levelForTotalXp((int) $progress->total_xp);
                if ((int) $progress->level !== $level) {
                    DB::table('player_progress')
                        ->where('id', $progress->id)
                        ->update(['level' => $level]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creature_selection_acquisitions');
    }
};
