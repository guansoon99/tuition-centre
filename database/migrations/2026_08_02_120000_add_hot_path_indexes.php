<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds three indexes to hot-path columns that the audit surfaced:
 *
 *  - courses.is_active                — filtered on nearly every dashboard/listing/coursesForSelect call
 *  - enrollments (user_id, is_active) — home-page enrollment lookup runs on every logged-in page
 *  - access_logs.accessed_at          — admin log pagination sorts on this without a WHERE
 *
 * Everything else in the schema was already covered by prior migrations
 * (foreign-key indexes, sort_order composites, existing single-column indexes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->index('is_active', 'courses_is_active_index');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->index(['user_id', 'is_active'], 'enrollments_user_id_is_active_index');
        });

        Schema::table('access_logs', function (Blueprint $table) {
            $table->index('accessed_at', 'access_logs_accessed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex('courses_is_active_index');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex('enrollments_user_id_is_active_index');
        });

        Schema::table('access_logs', function (Blueprint $table) {
            $table->dropIndex('access_logs_accessed_at_index');
        });
    }
};
