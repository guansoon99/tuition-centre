<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Set on the first file upload; kept as the "when did they submit"
            // marker even after edits (files added/removed).
            $table->dateTime('submitted_at')->nullable();

            // Free-form: teacher can enter "85", "A+", "Pass", etc.
            $table->string('grade', 32)->nullable();
            $table->text('comment')->nullable();
            $table->dateTime('graded_at')->nullable();
            $table->foreignId('graded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['material_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
