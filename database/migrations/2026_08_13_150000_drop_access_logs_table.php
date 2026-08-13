<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops access_logs.
 *
 * The feature recorded who viewed or downloaded each material, and was read
 * by exactly one admin page that had no sidebar link — reachable only by
 * typing /access-logs. Nothing else depended on it, so it was removed rather
 * than left as an unread write on every material download.
 *
 * ⚠ This destroys the recorded history. down() recreates the table with the
 * original schema, but the rows are gone for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('access_logs');
    }

    public function down(): void
    {
        // Mirrors 2026_05_10_000007_create_access_logs_table so a rollback
        // leaves the schema as it was — empty, but structurally correct.
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->enum('action', ['view', 'download']);
            $table->string('ip_address', 45);
            $table->string('user_agent')->nullable();
            $table->timestamp('accessed_at');

            $table->index(['user_id', 'accessed_at']);
            $table->index(['material_id', 'accessed_at']);
            $table->index('accessed_at', 'access_logs_accessed_at_index');
        });
    }
};
