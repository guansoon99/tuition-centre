<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merge the `course_teacher` pivot into `enrollments`.
 *
 * Before: two separate tables representing the same shape (user ↔ course
 * with a date range) — one for students, one for teachers. Every query had
 * to remember which side of the split it lived on.
 *
 * After: one `enrollments` table with a `role_on_course` column
 * ('student' | 'teacher'). Naming stays for backwards code compat.
 *
 * Data migration copies every `course_teacher` row into `enrollments` as
 * role='teacher', mapping assigned_at → enrolled_at and ends_at → expires_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Extend enrollments with the role column ---------------------
        Schema::table('enrollments', function (Blueprint $table) {
            $table->string('role_on_course', 20)->default('student')->after('course_id');
        });

        // Existing rows are all students — the default already handles them,
        // but be explicit so a future default change can't drift them.
        DB::table('enrollments')->update(['role_on_course' => 'student']);

        // --- 2. Relax the old (user_id, course_id) uniqueness ---------------
        // A user can now be both a student AND a teacher of the same course
        // (e.g. an assistant who is also taking the class). Replace with a
        // composite that includes the role.
        //
        // SQLite/MySQL both accept dropUnique on Laravel's default index name.
        // If the DB is SQLite the table gets rebuilt under the hood — that's
        // still fast at this scale.
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'course_id']);
            $table->unique(['user_id', 'course_id', 'role_on_course']);
        });

        // Add an index for the "list all teachers of a course" query that's
        // about to become the new hot path.
        Schema::table('enrollments', function (Blueprint $table) {
            $table->index(['course_id', 'role_on_course'], 'enrollments_course_role_index');
        });

        // --- 3. Copy course_teacher rows into enrollments -------------------
        // Do this row-by-row (not INSERT..SELECT) so cross-driver quirks with
        // timestamp defaults / soft-delete columns don't bite us.
        if (Schema::hasTable('course_teacher')) {
            $now = now();
            DB::table('course_teacher')->orderBy('id')->each(function ($row) use ($now) {
                DB::table('enrollments')->insert([
                    'user_id'          => $row->user_id,
                    'course_id'        => $row->course_id,
                    'role_on_course'   => 'teacher',
                    // course_teacher.assigned_at → enrollments.enrolled_at
                    'enrolled_at'      => $row->assigned_at ?? $row->created_at ?? $now,
                    // course_teacher.ends_at → enrollments.expires_at (nullable = forever)
                    'expires_at'       => $row->ends_at ?? null,
                    'is_active'        => true,
                    'last_accessed_at' => $row->last_accessed_at ?? null,
                    'created_at'       => $row->created_at ?? $now,
                    'updated_at'       => $row->updated_at ?? $now,
                    'deleted_at'       => null,
                ]);
            });

            // --- 4. Drop the now-empty course_teacher table -----------------
            Schema::drop('course_teacher');
        }
    }

    public function down(): void
    {
        // Recreate course_teacher.
        Schema::create('course_teacher', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'user_id']);
        });

        // Move teacher rows back.
        DB::table('enrollments')
            ->where('role_on_course', 'teacher')
            ->orderBy('id')
            ->each(function ($row) {
                DB::table('course_teacher')->insert([
                    'course_id'        => $row->course_id,
                    'user_id'          => $row->user_id,
                    'assigned_at'      => $row->enrolled_at,
                    'ends_at'          => $row->expires_at,
                    'last_accessed_at' => $row->last_accessed_at,
                    'created_at'       => $row->created_at,
                    'updated_at'       => $row->updated_at,
                ]);
            });

        // Delete the teacher rows from enrollments.
        DB::table('enrollments')->where('role_on_course', 'teacher')->delete();

        // Restore the original constraint layout.
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex('enrollments_course_role_index');
            $table->dropUnique(['user_id', 'course_id', 'role_on_course']);
            $table->unique(['user_id', 'course_id']);
            $table->dropColumn('role_on_course');
        });
    }
};
