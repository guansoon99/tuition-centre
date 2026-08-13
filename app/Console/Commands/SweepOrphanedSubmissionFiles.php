<?php

namespace App\Console\Commands;

use App\Models\SubmissionFile;
use App\Support\PrivateFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes submission objects that no database row points at.
 *
 * These exist because direct-to-R2 uploads are two separate requests. The
 * browser PUTs the file straight to the bucket, then tells us about it. If it
 * never gets to the second step — tab closed, network dropped, laptop shut —
 * the object is in the bucket and nothing here knows it happened. Unlike the
 * proxied path, there is no exception to catch: the failure occurs somewhere
 * this server cannot observe.
 *
 * So the only way to find them is to compare the bucket against the table,
 * which is what this does. It is the one caveat of presigned uploads that
 * has no clean fix — it can only be swept up afterwards.
 *
 * The grace period matters: an upload in flight right now has no row yet and
 * must not be deleted out from under the student.
 */
class SweepOrphanedSubmissionFiles extends Command
{
    protected $signature = 'submissions:sweep-orphans
                            {--hours=24 : Ignore objects younger than this}
                            {--dry-run : List what would be deleted, delete nothing}';

    protected $description = 'Delete submission files in storage that have no database row';

    public function handle(): int
    {
        $disk = Storage::disk(PrivateFile::disk());
        $cutoff = now()->subHours((int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');

        $objects = $disk->allFiles('submissions');

        if ($objects === []) {
            $this->info('No submission objects in storage.');

            return self::SUCCESS;
        }

        // One query rather than one per object — a bucket with thousands of
        // submissions would otherwise hammer the database.
        $known = SubmissionFile::whereIn('file_path', $objects)
            ->pluck('file_path')
            ->flip();

        $deleted = 0;
        $bytes = 0;

        foreach ($objects as $path) {
            if ($known->has($path)) {
                continue;
            }

            // An upload that is still in progress has no row yet. Only sweep
            // objects old enough that no browser could still be working on them.
            if ($disk->lastModified($path) > $cutoff->getTimestamp()) {
                continue;
            }

            $size = $disk->size($path);

            if ($dryRun) {
                $this->line("would delete  {$path}  (".number_format($size / 1024, 1).' KB)');
            } else {
                $disk->delete($path);
                $this->line("deleted  {$path}");
            }

            $deleted++;
            $bytes += $size;
        }

        $this->info(sprintf(
            '%s %d orphan(s), %s MB, from %d object(s) scanned.',
            $dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            number_format($bytes / 1048576, 2),
            count($objects),
        ));

        return self::SUCCESS;
    }
}
