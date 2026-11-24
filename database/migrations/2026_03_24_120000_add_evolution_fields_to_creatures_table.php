<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evolution metadata for the local catalog.
 *
 * Catalog listing shows only stage-1 (base) forms: evolves_from_id IS NULL.
 * Detail responses expose next evolutions via the self-referential relationship.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creatures', function (Blueprint $table) {
            $table->unsignedBigInteger('species_external_id')->nullable()->after('external_id');
            $table->unsignedBigInteger('evolves_from_species_external_id')->nullable()->after('species_external_id');
            $table->foreignId('evolves_from_id')
                ->nullable()
                ->after('evolves_from_species_external_id')
                ->constrained('creatures')
                ->nullOnDelete();
            $table->unsignedTinyInteger('evolution_stage')->default(1)->after('evolves_from_id');
            $table->boolean('is_default_form')->default(true)->after('evolution_stage');

            $table->index(['provider', 'species_external_id']);
            $table->index('evolves_from_id');
            $table->index(['is_default_form', 'evolves_from_id']);
        });
    }

    public function down(): void
    {
        Schema::table('creatures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evolves_from_id');
            $table->dropIndex(['provider', 'species_external_id']);
            $table->dropIndex(['is_default_form', 'evolves_from_id']);
            $table->dropColumn([
                'species_external_id',
                'evolves_from_species_external_id',
                'evolution_stage',
                'is_default_form',
            ]);
        });
    }
};
