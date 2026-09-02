<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the fold-state table a third answer.
 *
 * It stored a preference as row presence: present = collapsed, absent = open.
 * That is enough while "open" is the only default, but not once sections
 * collapse themselves by date — the table then has to distinguish
 *
 *     "the user deliberately opened this"   (leave it open)
 *     "the user has never touched it"       (let the date rule decide)
 *
 * which presence alone cannot express. A row now means the user has an
 * opinion and `collapsed` says which; no row means no opinion.
 *
 * Defaulting to true backfills correctly: every existing row was written to
 * mean collapsed.
 *
 * The table name is now a shade wrong — it holds expansions too — but
 * renaming it would touch every query for no behavioural gain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_collapsed_sections', function (Blueprint $table) {
            $table->boolean('collapsed')->default(true)->after('section_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_collapsed_sections', function (Blueprint $table) {
            $table->dropColumn('collapsed');
        });
    }
};
