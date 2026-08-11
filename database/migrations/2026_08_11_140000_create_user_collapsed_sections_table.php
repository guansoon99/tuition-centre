<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user fold-up state for course sections.
 *
 * Row present  = section is collapsed in this user's view.
 * Row absent   = section is open (the default).
 *
 * Toggling on the student course page flips the row's existence. Unique on
 * (user_id, section_id) so we never have duplicates. Both FKs cascade so
 * deleting a user or a section auto-cleans their preferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_collapsed_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_collapsed_sections');
    }
};
