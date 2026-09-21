<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['endpoint_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinations');
    }
};
