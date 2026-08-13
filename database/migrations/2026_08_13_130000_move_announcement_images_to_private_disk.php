<?php

use App\Support\PrivateFile;
use App\Support\PublicFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Moves announcement images from the public disk to the private one.
 *
 * They were being written to the web-served disk, which meant anyone with
 * the URL could fetch them without logging in — including images attached to
 * announcements scoped to a single course or role. They're now streamed
 * through AnnouncementImageController, which checks the caller is in the
 * announcement's audience.
 *
 * image_path values are unchanged; only the disk the bytes live on moves.
 *
 * Idempotent and non-fatal: a file already moved, or missing entirely, is
 * skipped rather than failing the deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move(PublicFile::disk(), PrivateFile::disk());
    }

    public function down(): void
    {
        $this->move(PrivateFile::disk(), PublicFile::disk());
    }

    private function move(string $fromDisk, string $toDisk): void
    {
        if ($fromDisk === $toDisk) {
            return;
        }

        $from = Storage::disk($fromDisk);
        $to = Storage::disk($toDisk);

        $paths = DB::table('announcements')
            ->whereNotNull('image_path')
            ->pluck('image_path');

        $moved = 0;

        foreach ($paths as $path) {
            if ($to->exists($path) || ! $from->exists($path)) {
                continue; // already moved, or nothing there to move
            }

            try {
                $to->put($path, $from->get($path));
                $from->delete($path);
                $moved++;
            } catch (\Throwable $e) {
                // A single unreadable file must not abort the deploy — the
                // image simply 404s until it's re-uploaded.
                Log::warning("Announcement image move failed for [{$path}]: ".$e->getMessage());
            }
        }

        if ($moved > 0) {
            Log::info("Moved {$moved} announcement image(s) from [{$fromDisk}] to [{$toDisk}].");
        }
    }
};
