<?php

namespace App\Http\Controllers\Teacher;

use App\Exports\SubmissionStatusExport;
use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\Submission;
use App\Support\PrivateFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class SubmissionController extends Controller
{
    /**
     * Save (or clear) a student's grade + comment. The teacher must own the
     * course the submission belongs to. Empty grade + empty comment clears
     * the grading — useful for undoing a wrong entry.
     */
    public function grade(Request $request, Submission $submission): RedirectResponse|Response
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

        // The grading modal posts this over fetch and stays open, so it wants
        // an answer rather than a page — a redirect would be followed and pull
        // the whole roster back for nothing.
        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect()
            ->route('materials.view', $material)
            ->with('status', $hasGrading ? 'Grade saved.' : 'Grade cleared.');
    }

    /**
     * Body of the grading modal, fetched when it opens.
     *
     * Same reasoning as the material edit modal: a class of thirty students
     * would otherwise ship thirty copies of this form, with thirty file
     * inputs, before anyone clicked anything.
     */
    public function gradeModal(Request $request, Submission $submission): View
    {
        $material = $submission->material;
        $user = $request->user();

        if (! $user->teaches($material->section->course) && ! $user->hasRole('admin')) {
            abort(403);
        }

        $submission->load(['student', 'files', 'feedbackFiles']);

        return view('teacher.materials._grade-modal-body', [
            'submission' => $submission,
            'material' => $material,
        ]);
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
        $this->assertMayDownload($request, $material);

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
            $base = $this->safeName($submission->student->name ?? '', 'unknown');
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

        $filename = $this->downloadName($material, 'zip');

        return response()
            ->download($tmpPath, $filename, ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }

    /**
     * The roster as a spreadsheet: who has handed in and who has not.
     *
     * Unlike downloadAll this is useful precisely when nothing has been
     * submitted, so it never refuses on an empty assignment — an export
     * listing every student as "Not submitted" is the chasing list.
     */
    public function downloadStatus(Request $request, Material $material): BinaryFileResponse
    {
        $this->assertMayDownload($request, $material);

        return Excel::download(
            new SubmissionStatusExport($material),
            $this->downloadName($material, 'xlsx'),
        );
    }

    /** Both downloads are named after the assignment; only the extension differs. */
    private function downloadName(Material $material, string $extension): string
    {
        return $this->safeName((string) $material->title, 'assignment').'.'.$extension;
    }

    /**
     * Turn user-entered text into something safe to use as a file or folder
     * name, keeping as much of it as possible.
     *
     * Deliberately NOT Str::slug. Slug strips non-ASCII outright, which here
     * meant Chinese assignment titles produced an empty filename, and — worse
     * — every Chinese-named student landed in a ZIP folder called "unknown",
     * disambiguated only by user id. Both are the common case in this school,
     * not an edge case.
     *
     * Removed instead: path separators, so a name cannot point elsewhere;
     * control characters; and the set Windows refuses in a name, so a title
     * like "Homework: Week 1" stays saveable. Spaces, Chinese and ordinary
     * punctuation all survive — ZipArchive stores UTF-8 entry names, and
     * Laravel percent-encodes the filename into Content-Disposition.
     */
    private function safeName(string $raw, string $fallback): string
    {
        $name = basename(str_replace('\\', '/', $raw));
        $name = preg_replace('/[[:cntrl:]]/u', '', $name) ?? '';
        $name = str_replace(['"', '*', '/', ':', '<', '>', '?', '\\', '|'], '', $name);
        $name = trim($name, " .\t");

        return $name !== '' ? Str::limit($name, 150, '') : $fallback;
    }

    /**
     * Both downloads expose the whole class's work, so both are limited to
     * staff on this course. Shared rather than repeated: a check that exists
     * twice is a check that eventually only gets fixed once.
     */
    private function assertMayDownload(Request $request, Material $material): void
    {
        $user = $request->user();

        if (! $user->teaches($material->section->course) && ! $user->hasRole('admin')) {
            abort(403);
        }

        if ($material->type !== Material::TYPE_ASSIGNMENT) {
            abort(404);
        }
    }
}
