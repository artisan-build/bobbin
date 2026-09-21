<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captured_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('method', 16);
            $table->text('raw_query');
            $table->json('headers');
            $table->binary('body');
            $table->timestampTz('received_at', precision: 6);
            $table->index(['endpoint_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captured_requests');
    }
};
