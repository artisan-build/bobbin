<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropForeign(['destination_id']);
            $table->foreign('destination_id')->references('id')->on('destinations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropForeign(['destination_id']);
            $table->foreign('destination_id')->references('id')->on('destinations')->cascadeOnDelete();
        });
    }
};
