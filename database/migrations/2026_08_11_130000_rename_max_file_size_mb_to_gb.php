<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switches materials.max_file_size_mb to max_file_size_gb. Existing MB
 * values are rounded UP to the nearest whole GB (so a 500MB cap becomes
 * 1GB — never accidentally tighter than the teacher configured).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_file_size_gb')->nullable()->after('due_date');
        });

        DB::table('materials')
            ->whereNotNull('max_file_size_mb')
            ->update([
                'max_file_size_gb' => DB::raw('CAST((max_file_size_mb + 1023) / 1024 AS INTEGER)'),
            ]);

        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('max_file_size_mb');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_file_size_mb')->nullable()->after('due_date');
        });

        DB::table('materials')
            ->whereNotNull('max_file_size_gb')
            ->update(['max_file_size_mb' => DB::raw('max_file_size_gb * 1024')]);

        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('max_file_size_gb');
        });
    }
};
