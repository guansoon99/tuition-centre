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
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportStudentsController extends Controller
{
    public function show(): View
    {
        return view('admin.import.show', [
            'preview' => Session::pull('preview'),
            'result' => Session::pull('result'),
            'credentialsFile' => Session::get('credentials_file'),
        ]);
    }

    public function preview(Request $request, StudentImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $rows = $importer->parseFile($request->file('file'));
        $result = $importer->processRows($rows, dryRun: true);

        Session::flash('preview', $result);

        return back();
    }

    public function run(Request $request, StudentImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $rows = $importer->parseFile($request->file('file'));
        $result = $importer->processRows($rows, dryRun: false);

        if (! empty($result['ok'])) {
            $filename = 'students_credentials_'.now()->format('Y-m-d_His').'.xlsx';
            $exportPath = 'exports/'.$filename;
            Excel::store(new StudentCredentialsExport($result['ok']), $exportPath, 'local');

            Session::put('credentials_file', $exportPath);
        }

        Session::flash('result', $result);
        Session::flash('status', 'Import done. Created: '.count($result['ok']).', skipped: '.count($result['skipped']).', errors: '.count($result['errors']).'.');

        return back();
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
