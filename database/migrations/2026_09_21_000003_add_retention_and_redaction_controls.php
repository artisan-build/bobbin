<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('endpoints', function (Blueprint $table): void {
            $table->unsignedSmallInteger('retention_days')->default(30);
            $table->json('redacted_header_names')->nullable();
            $table->boolean('body_redaction')->default(false);
        });

        Schema::table('captured_requests', function (Blueprint $table): void {
            $table->json('redacted_header_names')->nullable();
            $table->boolean('body_redacted')->default(false);
        });

        DB::table('endpoints')->update(['redacted_header_names' => '[]']);
        DB::table('captured_requests')->update(['redacted_header_names' => '[]']);
    }

    public function down(): void
    {
        Schema::table('captured_requests', function (Blueprint $table): void {
            $table->dropColumn(['redacted_header_names', 'body_redacted']);
        });

        Schema::table('endpoints', function (Blueprint $table): void {
            $table->dropColumn(['retention_days', 'redacted_header_names', 'body_redaction']);
        });
    }
};
