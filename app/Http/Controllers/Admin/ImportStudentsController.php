<?php

namespace App\Http\Controllers\Admin;

use App\Exports\StudentImportSampleExport;
use App\Http\Controllers\Controller;
use App\Jobs\ImportStudents;
use App\Models\Course;
use App\Models\StudentImport;
use App\Services\StudentImporter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Student import: upload → preview (dry run, in the request) → run (a
 * background job; see ImportStudents) → result and credentials sheet.
 *
 * The preview is fast and stays synchronous. The real run is not: every new
 * student costs a bcrypt hash, so it goes to the queue and the page polls
 * its progress until the job reports done or failed.
 */
class ImportStudentsController extends Controller
{
    public function show(Request $request): View
    {
        $preview = Session::pull('preview');
        $userId = $request->user()->id;

        // An import still queued or running: the page shows its progress and
        // nothing else until it finishes.
        $active = StudentImport::activeFor($userId)->latest()->first();

        // A finished import to show the result of — the one the progress page
        // redirected to.
        $import = null;
        if (! $active && $request->filled('import')) {
            $import = StudentImport::query()
                ->where('user_id', $userId)
                ->whereIn('status', [StudentImport::STATUS_DONE, StudentImport::STATUS_FAILED])
                ->find($request->integer('import'));
        }

        // The most recent credentials sheet, for "Download Last Credentials".
        $lastCredentials = StudentImport::query()
            ->where('user_id', $userId)
            ->whereNotNull('credentials_path')
            ->latest()
            ->first();

        // No preview, no result, no run in flight, but a stashed upload from a
        // previous visit — clean it up so /imports does not accumulate.
        if (! $preview && ! $import && ! $active && ($path = Session::get('import_file_path'))) {
            Storage::disk('local')->delete($path);
            Session::forget(['import_file_path', 'import_file_original_name']);
        }

        return view('admin.import.show', [
            'preview' => $preview,
            'active' => $active,
            'import' => $import,
            'lastCredentials' => $lastCredentials,
            'stashedFileName' => Session::get('import_file_original_name'),
        ]);
    }

    public function preview(Request $request, StudentImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        // Replace any previously stashed file so /imports doesn't accumulate.
        if ($oldPath = Session::get('import_file_path')) {
            Storage::disk('local')->delete($oldPath);
        }

        $uploaded = $request->file('file');
        $stored = $uploaded->store('imports', 'local');
        Session::put('import_file_path', $stored);
        Session::put('import_file_original_name', $uploaded->getClientOriginalName());

        $result = $importer->processRows($importer->parseFile($uploaded), dryRun: true);

        Session::flash('preview', $result);

        return back();
    }

    /**
     * Start the import: record it, hand the file to the job, and send the
     * admin to the page that follows its progress.
     */
    public function run(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (StudentImport::activeFor($user->id)->exists()) {
            return redirect()
                ->route('import.show')
                ->withErrors(['file' => 'An import is already running. Wait for it to finish.']);
        }

        [$storedPath, $originalName] = $this->resolveStoredFile($request);

        if ($storedPath === null) {
            return back()->withErrors(['file' => 'No file to import — please pick a file first.']);
        }

        $import = StudentImport::create([
            'user_id' => $user->id,
            'original_name' => $originalName,
            'stored_path' => $storedPath,
            // 'all' means the admin explicitly opted into creating duplicate
            // names; anything else is the default (skip duplicates).
            'mode' => $request->input('mode') === 'all' ? StudentImport::MODE_ALL : StudentImport::MODE_SKIP,
            'status' => StudentImport::STATUS_QUEUED,
        ]);

        // The upload now belongs to the import; the job deletes it when done.
        Session::forget(['import_file_path', 'import_file_original_name']);

        try {
            ImportStudents::dispatch($import);
        } catch (Throwable $e) {
            // Only reachable on the sync queue (staging, tests), where the job
            // runs inside this request and its failure surfaces here. The job
            // has already recorded the failure on the row; the page shows it.
            report($e);
        }

        return redirect()->route('import.show', ['import' => $import->id]);
    }

    /** What the progress bar polls. */
    public function status(Request $request, StudentImport $import): JsonResponse
    {
        abort_unless($import->user_id === $request->user()->id, 403);

        return response()->json($import->progressPayload());
    }

    /**
     * Discard the stashed preview file. Used when the admin picks
     * "Cancel" on the duplicate-name alert.
     */
    public function cancel(): RedirectResponse
    {
        if ($stashedPath = Session::get('import_file_path')) {
            Storage::disk('local')->delete($stashedPath);
        }
        Session::forget(['import_file_path', 'import_file_original_name']);

        return redirect()
            ->route('import.show')
            ->with('status', 'Import cancelled.');
    }

    /**
     * The file to import, as a path on the local disk: one picked on this
     * request is stored like the preview stores its upload; otherwise the
     * file stashed during the preview step is used, so the admin can click
     * Import without re-uploading.
     *
     * @return array{0:?string,1:?string} [stored path, original name]
     */
    private function resolveStoredFile(Request $request): array
    {
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => ['file', 'mimes:xlsx,xls,csv', 'max:5120'],
            ]);

            if ($oldPath = Session::get('import_file_path')) {
                Storage::disk('local')->delete($oldPath);
            }

            $uploaded = $request->file('file');

            return [$uploaded->store('imports', 'local'), $uploaded->getClientOriginalName()];
        }

        $stashedPath = Session::get('import_file_path');

        if (! $stashedPath || ! Storage::disk('local')->exists($stashedPath)) {
            return [null, null];
        }

        return [$stashedPath, Session::get('import_file_original_name', basename($stashedPath))];
    }

    public function downloadSample(): BinaryFileResponse
    {
        $sampleCode = Course::where('is_active', true)->orderBy('id')->value('code');

        return Excel::download(
            new StudentImportSampleExport($sampleCode),
            'students_import_sample.xlsx'
        );
    }

    public function downloadCredentials(Request $request, StudentImport $import): StreamedResponse|RedirectResponse
    {
        abort_unless($import->user_id === $request->user()->id, 403);

        if (! $import->credentials_path || ! Storage::disk('local')->exists($import->credentials_path)) {
            return redirect()
                ->route('import.show')
                ->withErrors(['credentials' => 'No credentials file available for that import.']);
        }

        return Storage::disk('local')->download($import->credentials_path);
    }
}
