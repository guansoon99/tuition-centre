<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the video_link material type — external_link served the same
 * purpose (arbitrary URL opened in a new tab). Existing video_link rows
 * are converted to external_link so no data is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('materials')
            ->where('type', 'video_link')
            ->update(['type' => 'external_link']);
    }

    public function down(): void
    {
        // No-op: we can't tell which of the (now-merged) external_link
        // rows originally were videos.
    }
};
