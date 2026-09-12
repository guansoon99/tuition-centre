<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per student import run, so the import can happen in a background
 * job while the page shows its progress and, afterwards, its result and
 * credentials sheet. Before this the whole import ran inside the request
 * and PHP-FPM's 30-second limit cut an 800-row file off at row 400.
 *
 * Live progress is NOT on this row: the importer runs in one transaction,
 * and an update from inside it would be invisible to the polling request
 * until commit. Progress goes through the cache (StudentImport::progress).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mode', 10)->default('skip');
            $table->string('status', 10)->default('queued')->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->json('result')->nullable();
            $table->string('credentials_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_imports');
    }
};
