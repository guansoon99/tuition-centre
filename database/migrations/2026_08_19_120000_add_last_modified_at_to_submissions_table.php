<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the student last changed their own submission.
 *
 * None of the existing columns can answer this:
 *
 *  - submitted_at is deliberately frozen at the first upload, so it never
 *    moves afterwards;
 *  - updated_at moves when the TEACHER grades, which is not the student
 *    touching their work;
 *  - max(files.uploaded_at) disappears the moment the last file is removed,
 *    so the record of that removal is lost with it.
 *
 * Removing a file is a modification, and the point of this column is that it
 * survives one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dateTime('last_modified_at')->nullable()->after('submitted_at');
        });

        // Existing submissions predate the column. The most recent upload is
        // the best answer available for them; submitted_at covers the rows
        // whose files are already gone.
        DB::table('submissions')->update([
            'last_modified_at' => DB::raw(
                '(SELECT MAX(uploaded_at) FROM submission_files WHERE submission_files.submission_id = submissions.id)'
            ),
        ]);

        DB::table('submissions')
            ->whereNull('last_modified_at')
            ->update(['last_modified_at' => DB::raw('submitted_at')]);
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('last_modified_at');
        });
    }
};
