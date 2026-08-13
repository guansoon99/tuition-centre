<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes for users.
 *
 * Deleting a user used to be a hard DELETE, which cascaded away their
 * enrollments, access logs, course views, submissions and fold-state. That
 * made an accidental bulk delete unrecoverable and wiped the audit trail.
 *
 * With deleted_at present the row survives, so:
 *   - the user disappears from every Eloquent query (global scope) and can
 *     no longer authenticate, but
 *   - their history stays intact and the account can be restored.
 *
 * Note the trade-off: a soft-deleted user keeps their unique `username` and
 * `email`. UsernameGenerator is updated to look through trashed rows so it
 * skips past those instead of colliding on insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
