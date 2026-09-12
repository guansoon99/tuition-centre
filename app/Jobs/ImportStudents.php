<?php

namespace App\Jobs;

use App\Exports\StudentCredentialsExport;
use App\Models\StudentImport;
use App\Services\StudentImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Runs a student import in the background and keeps its StudentImport row
 * (and the cached progress the page polls) up to date.
 *
 * Why a job: every imported student costs a bcrypt hash, ~70 ms on the
 * production box, so an 800-row file is a minute of work. Inside a web
 * request that met PHP-FPM's 30-second limit at row 400 (2026-09-11: seven
 * such runs, 2,791 accounts, 399 names duplicated), and would meet
 * Cloudflare's 100-second one after that. A worker has neither.
 *
 * Never retried by the queue ($tries = 1): the importer runs in one
 * transaction, so a failure leaves nothing behind, and whether to run the
 * file again is a person's decision, made from the failure shown on the page.
 */
class ImportStudents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public StudentImport $import) {}

    public function handle(StudentImporter $importer): void
    {
        $import = $this->import->fresh();

        // Already handled (a duplicate delivery, or a manual re-run of the
        // job): do not import the same file twice.
        if ($import === null || $import->status !== StudentImport::STATUS_QUEUED) {
            return;
        }

        $import->update(['status' => StudentImport::STATUS_RUNNING, 'started_at' => now()]);

        try {
            $file = new UploadedFile(
                Storage::disk('local')->path($import->stored_path),
                $import->original_name,
                null,
                null,
                true,
            );

            $records = $importer->parseFile($file);
            $total = count($records);
            $import->update(['total' => $total]);
            $import->recordProgress(0, $total);

            $result = $importer->processRows(
                $records,
                dryRun: false,
                allowDuplicates: $import->mode === StudentImport::MODE_ALL,
                onProgress: fn (int $done) => $import->recordProgress($done, $total),
            );

            $credentialsPath = null;
            if (! empty($result['ok']) || ! empty($result['skipped'])) {
                $credentialsPath = 'exports/students_credentials_'.now()->format('Y-m-d_His')."_{$import->id}.xlsx";
                Excel::store(
                    new StudentCredentialsExport($result['ok'], $result['skipped']),
                    $credentialsPath,
                    'local',
                );
            }

            // The full rows, passwords included: the results panel shows each
            // new student's password on screen, exactly as the inline import
            // did, and users.plain_password holds the same value anyway.
            $import->update([
                'status' => StudentImport::STATUS_DONE,
                'done' => $total,
                'result' => $result,
                'credentials_path' => $credentialsPath,
                'finished_at' => now(),
            ]);
            $import->recordProgress($total, $total);
        } catch (Throwable $e) {
            $this->markFailed($import, $e);

            throw $e;
        } finally {
            // The upload was the job's to consume, whichever way it went.
            Storage::disk('local')->delete($import->stored_path);
        }
    }

    /**
     * The queue's own failure hook — reached when the worker itself is
     * killed (timeout, out of memory) rather than when handle() threw, since
     * handle() has already recorded that case.
     */
    public function failed(?Throwable $e): void
    {
        if ($import = $this->import->fresh()) {
            $this->markFailed($import, $e);
        }
    }

    private function markFailed(StudentImport $import, ?Throwable $e): void
    {
        if ($import->status === StudentImport::STATUS_FAILED) {
            return;
        }

        $import->update([
            'status' => StudentImport::STATUS_FAILED,
            'error' => $e?->getMessage() ?: 'The import stopped before it finished.',
            'finished_at' => now(),
        ]);
    }
}
