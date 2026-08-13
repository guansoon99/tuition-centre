<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switches materials.max_file_size_gb back to max_file_size_mb.
 *
 * The GB column could not express a usable limit: it was validated
 * `min:1, max:5`, so the smallest cap a teacher could set was 1 GB — far
 * larger than any homework file, and larger than every real ceiling in the
 * request path (Cloudflare 100 MB, nginx, PHP post_max_size). The UI was
 * advertising a number that nothing could honour.
 *
 * Existing values are converted faithfully (gb * 1024) rather than clamped
 * down. A teacher who set 1 GB now genuinely gets 1 GB, because submissions
 * upload directly to R2 and no longer pass through PHP or Cloudflare's proxy.
 * The old number was never wrong in intent, only unachievable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->unsignedInteger('max_file_size_mb')->nullable()->after('due_date');
        });

        DB::table('materials')
            ->whereNotNull('max_file_size_gb')
            ->update(['max_file_size_mb' => DB::raw('max_file_size_gb * 1024')]);

        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('max_file_size_gb');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_file_size_gb')->nullable()->after('due_date');
        });

        // Round UP to the nearest whole GB so a restored cap is never tighter
        // than the teacher configured.
        DB::table('materials')
            ->whereNotNull('max_file_size_mb')
            ->update([
                'max_file_size_gb' => DB::raw('CAST((max_file_size_mb + 1023) / 1024 AS INTEGER)'),
            ]);

        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('max_file_size_mb');
        });
    }
};
