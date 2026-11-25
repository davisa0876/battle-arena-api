<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pve_battles', function (Blueprint $table): void {
            $table->string('end_reason', 32)->nullable()->after('outcome');
            $table->index(['user_id', 'outcome', 'end_reason']);
        });

        $abandonedIds = DB::table('battle_events')
            ->where('type', 'battle_finished')
            ->where('payload->reason', 'abandoned')
            ->pluck('battle_id');

        if ($abandonedIds->isNotEmpty()) {
            DB::table('pve_battles')
                ->whereIn('id', $abandonedIds)
                ->update(['end_reason' => 'abandoned']);
        }

        DB::table('pve_battles')
            ->where('status', 'finished')
            ->whereNotNull('outcome')
            ->whereNull('end_reason')
            ->update(['end_reason' => 'combat']);
    }

    public function down(): void
    {
        Schema::table('pve_battles', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'outcome', 'end_reason']);
            $table->dropColumn('end_reason');
        });
    }
};
