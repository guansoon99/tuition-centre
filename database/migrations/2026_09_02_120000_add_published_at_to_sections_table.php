<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record *when* a section was published, not just that it was.
 *
 * `is_published` is a boolean and carries no date, and `scheduled_at` is
 * optional — most sections have none — so neither can answer "has this been
 * up for more than a week?". That question is what decides whether a section
 * starts folded, so it needs a column of its own.
 *
 * Null means not published. It is never null for anything a student can see.
 *
 * The backfill has to invent a date for rows that predate the column:
 *
 *   scheduled_at, when set  — that IS the moment the section went live, and
 *                             it is more accurate than the creation time
 *   created_at otherwise    — the only other honest signal available
 *
 * Unpublished rows stay null rather than being given a fictional date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('is_published');
        });

        DB::table('sections')
            ->where('is_published', true)
            ->update(['published_at' => DB::raw('COALESCE(scheduled_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
