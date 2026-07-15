<?php

namespace App\Http\Controllers\Admin;

use App\Exports\StudentCredentialsExport;
use App\Exports\StudentImportSampleExport;
use App\Models\Course;
use App\Services\StudentImporter;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportStudentsController extends Controller
{
    public function show(): View
    {
        $preview = Session::pull('preview');
        $result = Session::pull('result');

        // No active preview/result on this request, but there's still a
        // stashed file from a previous session — clean it up so we don't
        // leave orphaned uploads on disk.
        if (! $preview && ! $result && ($path = Session::get('import_file_path'))) {
            Storage::disk('local')->delete($path);
            Session::forget(['import_file_path', 'import_file_original_name']);
        }

        return view('admin.import.show', [
            'preview' => $preview,
            'result' => $result,
            'credentialsFile' => Session::get('credentials_file'),
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

    public function run(Request $request, StudentImporter $importer): RedirectResponse
    {
        $file = $this->resolveImportFile($request);

        if ($file === null) {
            return back()->withErrors(['file' => 'No file to import — please pick a file first.']);
        }

        // 'all' means the admin explicitly opted into creating duplicate
        // names; anything else falls back to the default (skip duplicates).
        $allowDuplicates = $request->input('mode') === 'all';

        $result = $importer->processRows(
            $importer->parseFile($file),
            dryRun: false,
            allowDuplicates: $allowDuplicates,
        );

        if (! empty($result['ok']) || ! empty($result['skipped'])) {
            $filename = 'students_credentials_'.now()->format('Y-m-d_His').'.xlsx';
            $exportPath = 'exports/'.$filename;
            Excel::store(
                new StudentCredentialsExport($result['ok'] ?? [], $result['skipped'] ?? []),
                $exportPath,
                'local'
            );

            Session::put('credentials_file', $exportPath);
        }

        // Done with the import — clear the stashed upload.
        if ($stashedPath = Session::get('import_file_path')) {
            Storage::disk('local')->delete($stashedPath);
            Session::forget(['import_file_path', 'import_file_original_name']);
        }

        Session::flash('result', $result);
        Session::flash('status', 'Import done. Created: '.count($result['ok']).', skipped: '.count($result['skipped']).', errors: '.count($result['errors']).'.');

        return back();
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
     * Use the file the admin just picked if one is present; otherwise fall
     * back to the file stashed during the preview step so the admin can
     * click Import without re-uploading.
     */
    private function resolveImportFile(Request $request): ?UploadedFile
    {
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => ['file', 'mimes:xlsx,xls,csv', 'max:5120'],
            ]);

            return $request->file('file');
        }

        $stashedPath = Session::get('import_file_path');
        if (! $stashedPath || ! Storage::disk('local')->exists($stashedPath)) {
            return null;
        }

        $fullPath = Storage::disk('local')->path($stashedPath);
        $originalName = Session::get('import_file_original_name', basename($stashedPath));

        return new UploadedFile($fullPath, $originalName, null, null, true);
    }

    public function downloadSample(): BinaryFileResponse
    {
        $sampleCode = Course::where('is_active', true)->orderBy('id')->value('code');

        return Excel::download(
            new StudentImportSampleExport($sampleCode),
            'students_import_sample.xlsx'
        );
    }

    public function downloadCredentials(): StreamedResponse|RedirectResponse
    {
        $path = Session::get('credentials_file');

        if (! $path || ! Storage::disk('local')->exists($path)) {
            return back()->withErrors(['credentials' => 'No credentials file available. Run an import first.']);
        }

        return Storage::disk('local')->download($path);
    }
}
