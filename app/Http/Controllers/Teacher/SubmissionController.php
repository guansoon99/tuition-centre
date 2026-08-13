<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\Submission;
use App\Support\PrivateFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class SubmissionController extends Controller
{
    /**
     * Save (or clear) a student's grade + comment. The teacher must own the
     * course the submission belongs to. Empty grade + empty comment clears
     * the grading — useful for undoing a wrong entry.
     */
    public function grade(Request $request, Submission $submission): RedirectResponse
    {
        $material = $submission->material;
        $course = $material->section->course;
        $user = $request->user();

        if (! $user->teaches($course) && ! $user->hasRole('admin')) {
            abort(403);
        }

        $data = $request->validate([
            'grade' => ['nullable', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $grade = trim($data['grade'] ?? '');
        $comment = trim($data['comment'] ?? '');
        $hasGrading = $grade !== '' || $comment !== '';

        $submission->update([
            'grade' => $grade !== '' ? $grade : null,
            'comment' => $comment !== '' ? $comment : null,
            'graded_at' => $hasGrading ? now() : null,
            'graded_by_user_id' => $hasGrading ? $user->id : null,
        ]);

        return redirect()
            ->route('materials.view', $material)
            ->with('status', $hasGrading ? 'Grade saved.' : 'Grade cleared.');
    }

    /**
     * Bundle every submission file for this assignment into a single ZIP,
     * one folder per student (folder name = slugified student name, e.g.
     * "alex_lee/"). Streams the ZIP as a download.
     *
     * Uses a temp file rather than in-memory buffering so 30 × 10 × 10MB
     * doesn't blow the request's memory limit. For local disks we hand the
     * on-disk path to ZipArchive (no read into PHP memory); for cloud
     * disks we fall back to reading each file's bytes.
     */
    public function downloadAll(Request $request, Material $material): BinaryFileResponse|RedirectResponse
    {
        $course = $material->section->course;
        $user = $request->user();

        if (! $user->teaches($course) && ! $user->hasRole('admin')) {
            abort(403);
        }
        if ($material->type !== Material::TYPE_ASSIGNMENT) {
            abort(404);
        }

        $submissions = Submission::with(['student', 'files'])
            ->where('material_id', $material->id)
            ->get()
            ->filter(fn ($s) => $s->files->isNotEmpty())
            ->values();

        if ($submissions->isEmpty()) {
            return back()->withErrors(['zip' => 'No submissions to download yet.']);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'submissions_').'.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpPath);
            abort(500, 'Could not create ZIP.');
        }

        $usedFolders = [];

        foreach ($submissions as $submission) {
            $base = Str::slug($submission->student->name ?? '', '_') ?: 'unknown';
            // Two students with the same slugified name → disambiguate with id.
            $folder = $base;
            if (in_array($folder, $usedFolders, true)) {
                $folder = $base.'_'.$submission->student->id;
            }
            $usedFolders[] = $folder;

            // Track names already used inside THIS student's folder so two
            // uploads with identical original_name don't clobber each other
            // in the zip. Mirrors Windows/macOS "foo (2).pdf" behavior.
            $usedInFolder = [];

            foreach ($submission->files as $file) {
                if (! PrivateFile::exists($file->file_path)) {
                    continue;
                }
                // Strip path separators from the original name so a crafted
                // upload like "../foo.pdf" can't escape its folder.
                $safeName = str_replace(['/', '\\'], '_', $file->original_name);

                $uniqueName = $safeName;
                if (in_array($uniqueName, $usedInFolder, true)) {
                    $ext = pathinfo($safeName, PATHINFO_EXTENSION);
                    $stem = pathinfo($safeName, PATHINFO_FILENAME);
                    $suffix = $ext !== '' ? '.'.$ext : '';
                    $n = 2;
                    do {
                        $uniqueName = $stem.' ('.$n.')'.$suffix;
                        $n++;
                    } while (in_array($uniqueName, $usedInFolder, true));
                }
                $usedInFolder[] = $uniqueName;

                $entry = $folder.'/'.$uniqueName;

                // Prefer streaming from a local path so a big submission
                // never gets buffered in PHP memory. Cloud disks (R2/S3)
                // have no local path, so fall back to reading the bytes.
                $localPath = PrivateFile::path($file->file_path);
                if ($localPath !== null) {
                    $zip->addFile($localPath, $entry);
                } else {
                    $zip->addFromString($entry, PrivateFile::get($file->file_path));
                }
            }
        }

        $zip->close();

        $filename = (Str::slug($material->title ?: 'assignment', '_') ?: 'assignment').'_submissions.zip';

        return response()
            ->download($tmpPath, $filename, ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}
