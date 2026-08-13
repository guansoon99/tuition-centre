<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for foreign keys that sit on real query paths.
 *
 * MySQL/InnoDB auto-creates an index behind every FK constraint. SQLite does
 * not — and this app runs SQLite in dev AND on the server, so these columns
 * were being full-scanned.
 *
 * Deliberately not indexing every FK. An index costs write time and space, so
 * these are only the three with a query actually filtering on them:
 *
 *   submission_files.submission_id
 *       Read on every assignment page (a teacher's roster loads files for
 *       each student) and again when building the download-all ZIP.
 *
 *   announcements.course_id
 *       User::visibleAnnouncements() does whereIn('course_id', ...) twice.
 *       Runs on the home page and behind every announcement image request.
 *
 *   course_views.course_id
 *       Joined in HomeController's recently-accessed query, and scanned by
 *       the cascade when a course is hard-deleted.
 *
 * Left alone on purpose:
 *   submissions.user_id, user_collapsed_sections.section_id
 *       Already covered — each is the second column of a unique index whose
 *       first column is what the app actually filters on.
 *   submissions.graded_by_user_id, materials.uploaded_by_user_id,
 *   announcements.created_by_user_id, events.created_by_user_id
 *       Only ever read through a relation for display; nothing filters on
 *       them.
 *   role_has_permissions.role_id
 *       Spatie's table — leave its schema to the package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_files', function (Blueprint $table) {
            $table->index('submission_id', 'submission_files_submission_id_index');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->index('course_id', 'announcements_course_id_index');
        });

        Schema::table('course_views', function (Blueprint $table) {
            $table->index('course_id', 'course_views_course_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('submission_files', function (Blueprint $table) {
            $table->dropIndex('submission_files_submission_id_index');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex('announcements_course_id_index');
        });

        Schema::table('course_views', function (Blueprint $table) {
            $table->dropIndex('course_views_course_id_index');
        });
    }
};
