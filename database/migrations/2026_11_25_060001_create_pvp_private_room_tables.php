<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pvp_rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 32);
            $table->unsignedInteger('version')->default(1);
            $table->string('closed_reason', 32)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['host_user_id', 'status']);
            $table->index('status');
        });

        Schema::create('pvp_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->constrained('pvp_rooms')->cascadeOnDelete();
            $table->foreignId('inviter_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 32);
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->timestamps();

            $table->unique(['inviter_user_id', 'idempotency_key']);
            $table->index(['invitee_user_id', 'status']);
            $table->index(['inviter_user_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('room_id');
        });

        Schema::create('pvp_room_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('room_id')->constrained('pvp_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'user_id']);
            $table->index(['user_id', 'left_at']);
        });

        Schema::create('pvp_request_replays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('operation', 64);
            $table->string('idempotency_key', 120);
            $table->json('request_fingerprint');
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestamps();

            $table->unique(['user_id', 'operation', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pvp_request_replays');
        Schema::dropIfExists('pvp_room_members');
        Schema::dropIfExists('pvp_invitations');
        Schema::dropIfExists('pvp_rooms');
    }
};
