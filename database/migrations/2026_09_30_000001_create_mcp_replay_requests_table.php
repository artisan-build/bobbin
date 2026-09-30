<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_replay_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->string('actor_identifier');
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('delivery_id')->nullable();
            $table->unsignedBigInteger('delivery_capture_id')->nullable();
            $table->unsignedBigInteger('delivery_destination_id')->nullable();
            $table->string('delivery_status', 16)->nullable();
            $table->string('outcome', 16)->default('in_progress');
            $table->timestamps();
            $table->unique(['team_id', 'actor_identifier', 'idempotency_key'], 'mcp_replay_scope_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_replay_requests');
    }
};
