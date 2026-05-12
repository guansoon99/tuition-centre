<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * We switched from soft-delete to deactivate (is_active flag) for users.
 * Hard-delete any orphan soft-deleted rows and drop the deleted_at column
 * so the unique constraints on username/email behave intuitively.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'deleted_at')) {
            DB::table('users')->whereNotNull('deleted_at')->delete();

            Schema::table('users', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }
};
