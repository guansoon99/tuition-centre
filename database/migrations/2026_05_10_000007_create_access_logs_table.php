<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
    }
};
