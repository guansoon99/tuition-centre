<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Models\SubmissionFile;
use App\Support\CourseMedia;
use App\Support\PrivateFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes stored objects that nothing references any more.
 *
 * They exist because direct-to-R2 uploads are two separate requests. The
 * browser PUTs the file straight to the bucket, then tells us about it. If it
 * never gets to the second step — tab closed, network dropped, laptop shut —
 * the object is in the bucket and nothing here knows it happened. Unlike the
 * proxied path there is no exception to catch: the failure happens somewhere
 * this server cannot observe. The only way to find them is to compare the
 * bucket against what the app believes exists.
 *
 * Two prefixes, reconciled against different sources:
 *
 *   submissions/    against the submission_files table
 *   course-media/   against the URLs embedded in material bodies, because
 *                   course media has no table — the lesson text IS the index
 *
 * A teacher who uploads a video and then abandons the form orphans it the
 * same way, so this is not only a consequence of presigning.
 *
 * The grace period matters: an upload in flight right now is referenced by
 * nothing yet and must not be deleted out from under whoever is sending it.
 */
class SweepOrphanedSubmissionFiles extends Command
{
    protected $signature = 'submissions:sweep-orphans
                            {--hours=24 : Ignore objects younger than this}
                            {--dry-run : List what would be deleted, delete nothing}';

    protected $description = 'Delete submission and course-media files in storage that nothing references';

    public function handle(): int
    {
        $disk = Storage::disk(PrivateFile::disk());
        $cutoff = now()->subHours((int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');

        $objects = array_merge(
            $disk->allFiles('submissions'),
            $disk->allFiles('course-media'),
            $disk->allFiles('materials'),
        );

        if ($objects === []) {
            $this->info('Nothing in storage to reconcile.');

            return self::SUCCESS;
        }

        // One query per table rather than one per object — a bucket with
        // thousands of files would otherwise hammer the database.
        $known = SubmissionFile::whereIn('file_path', $objects)
            ->pluck('file_path')
            ->flip();

        // Material PDFs. withTrashed so a soft-deleted material keeps its file
        // for as long as the row could come back.
        $known = $known->union(
            Material::withTrashed()
                ->whereIn('file_path', $objects)
                ->pluck('file_path')
                ->flip()
        );

        // Course media has no table. A file's only link to a lesson is the URL
        // embedded in the rich-text body, so the bodies ARE the index — pull
        // every referenced filename out of them once and match on that.
        $referenced = $this->filenamesReferencedInLessons();

        $deleted = 0;
        $bytes = 0;

        foreach ($objects as $path) {
            if ($known->has($path)) {
                continue;
            }

            if (str_starts_with($path, 'course-media/') && isset($referenced[basename($path)])) {
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

    /**
     * Every course-media filename that appears in a lesson body.
     *
     * Returned as a lookup keyed by filename. Matching on the filename rather
     * than the whole URL keeps this working if the app's domain ever changes,
     * which would otherwise silently orphan every embedded file at once.
     */
    private function filenamesReferencedInLessons(): array
    {
        $found = [];

        // withTrashed, and chunkById rather than chunk.
        //
        // Soft-deleted materials must still count: their media is removed
        // deliberately by MaterialController::destroy, so anything left here
        // belonging to a trashed material got there some other way and is not
        // this command's to guess about. Without it, a soft delete would let
        // this sweep destroy media that a restore would need.
        //
        // chunkById because offset paging can skip a row, and a skipped body
        // makes its media look unreferenced — i.e. deletes a live file.
        Material::withTrashed()
            ->whereNotNull('body')
            ->select('id', 'body')
            ->chunkById(500, function ($rows) use (&$found) {
                foreach ($rows as $row) {
                    foreach (CourseMedia::filenamesIn($row->body) as $name) {
                        $found[$name] = true;
                    }
                }
            });

        return $found;
    }
}
