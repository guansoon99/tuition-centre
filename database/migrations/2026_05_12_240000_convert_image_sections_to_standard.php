<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the image files attached to any image-type sections so we
        // don't strand them on disk after the type is removed.
        DB::table('sections')
            ->where('type', 'image')
            ->whereNotNull('image_path')
            ->pluck('image_path')
            ->each(fn ($path) => Storage::disk('public')->delete($path));

        DB::table('sections')
            ->where('type', 'image')
            ->update([
                'type' => 'standard',
                'image_path' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No-op — we can't re-construct deleted image files.
    }
};
