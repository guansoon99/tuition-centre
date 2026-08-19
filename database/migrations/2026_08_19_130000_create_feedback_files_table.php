<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files a teacher sends back to one student — a marked-up copy, a rubric.
 *
 * A separate table rather than a `kind` column on submission_files, because
 * that relation is load-bearing in ways feedback must not touch:
 *
 *   $submission->files()->count() drives the student's upload cap, and
 *   $submission->files->isNotEmpty() is what "has submitted" means in five
 *   places.
 *
 * Sharing the table would have counted a teacher's returned file against the
 * student's own limit, and marked a student who never submitted as having
 * submitted. Both fail silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('submissions')->cascadeOnDelete();

            $table->string('file_path', 512);
            $table->string('original_name');
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 191)->nullable();
            $table->dateTime('uploaded_at');

            // Who returned it. Nullable so removing a staff account does not
            // take the student's feedback with it.
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_files');
    }
};
