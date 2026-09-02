<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a section opt out of folding itself away.
 *
 * Sections normally collapse a week after publication. Some should not —
 * a standing announcement, a countdown to an exam, a coursework brief that
 * stays relevant all term.
 *
 * A column of its own rather than a new `sections.type` value: type describes
 * what a section *is* and this describes how it behaves, so any section of
 * any type should be able to carry it. (That column is also vestigial —
 * written as 'standard' on create and read nowhere — so building on it would
 * mean reviving machinery that does not exist.)
 *
 * Nor is it inferred from the materials inside. Announcements and assignments
 * go stale too, and a rule reading them would pin a section open forever
 * because of a countdown whose date passed in July — while silently
 * un-folding old sections the moment someone added an assignment to one.
 *
 * Defaults false: every existing section keeps folding as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->boolean('never_collapses')->default(false)->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('never_collapses');
        });
    }
};
