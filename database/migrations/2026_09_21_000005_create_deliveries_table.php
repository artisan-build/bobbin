<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('destination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('captured_request_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('last_error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->index(['destination_id', 'created_at']);
            $table->index(['captured_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
