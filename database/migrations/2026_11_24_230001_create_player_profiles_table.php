<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Always stored lowercase so a plain unique index is case-insensitive on SQLite and PostgreSQL.
            $table->string('handle', 32)->unique();
            $table->string('display_name', 64);
            $table->string('bio', 500)->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('avatar_disk', 32)->nullable();
            $table->boolean('collection_is_public')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_profiles');
    }
};
