<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('public_id', 32)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoints');
    }
};
