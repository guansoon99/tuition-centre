<?php

namespace App\Http\Controllers\Teacher;

use App\Exports\SubmissionStatusExport;
use App\Http\Controllers\Controller;
use App\Models\Course;
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
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

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
            abort(403, Course::NOT_A_TEACHER_MESSAGE);
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
            abort(403, Course::NOT_A_TEACHER_MESSAGE);
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
     * "alex_lee/"). Streams the ZIP straight to the response.
     *
     * Each file is written into the archive one at a time, straight from
     * storage to the response, so a class of 30 × 10MB never blows the
     * request's memory limit — see the note in the method. zipEntries()
     * decides the layout; the streamed callback only moves bytes.
     */
    public function downloadAll(Request $request, Material $material): StreamedResponse|RedirectResponse
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

        // Which stored file lands at which path inside the ZIP. Worked out
        // here, before a byte is sent, so the streamed callback below only
        // moves data: a folder per student, uploads de-duplicated within it.
        $entries = $this->zipEntries($submissions);
        $filename = $this->downloadName($material, 'zip');

        // Streamed, not built-then-sent. The archive is written straight to
        // the response one file at a time and stored (not compressed --
        // submissions are already-compressed PDFs, images and video), so no
        // file is ever held whole in memory and the download starts at once,
        // whatever the class size. The old build-first path buffered every
        // file and met PHP's memory limit, and would meet Cloudflare's
        // 100-second one once the domain is live.
        $response = new StreamedResponse(function () use ($entries) {
            $zip = new ZipStream(
                outputName: null,
                sendHttpHeaders: false,
                defaultCompressionMethod: CompressionMethod::STORE,
                defaultEnableZeroHeader: true, // no seeking back -- this output is not seekable
                flushOutput: true,             // push each file out as it is written
            );

            foreach ($entries as [$entryName, $storedPath]) {
                $stream = PrivateFile::readStream($storedPath);
                if ($stream === null) {
                    continue; // a file that has gone missing skips, as before
                }

                try {
                    $zip->addFileFromStream($entryName, $stream);
                } finally {
                    fclose($stream);
                }

                // The teacher closed the download: stop reading from R2 rather
                // than pull every remaining file for nobody.
                if (connection_aborted()) {
                    return;
                }
            }

            $zip->finish();
        });

        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', PrivateFile::dispositionHeader('attachment', $filename));
        // Tell nginx not to buffer the whole archive before sending it on --
        // that would undo the streaming and reintroduce the memory cost.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * The ZIP layout: a [entry path, stored file path] pair for every file
     * that exists, one folder per student, uploads with the same name inside
     * a folder de-duplicated "foo (2).pdf" as Windows and macOS do.
     *
     * @param  \Illuminate\Support\Collection<int,\App\Models\Submission>  $submissions
     * @return list<array{0:string,1:string}>
     */
    private function zipEntries($submissions): array
    {
        $entries = [];
        $usedFolders = [];

        foreach ($submissions as $submission) {
            $base = $this->safeName($submission->student->name ?? '', 'unknown');
            // Two students with the same safe name: disambiguate with id.
            $folder = $base;
            if (in_array($folder, $usedFolders, true)) {
                $folder = $base.'_'.$submission->student->id;
            }
            $usedFolders[] = $folder;

            $usedInFolder = [];

            foreach ($submission->files as $file) {
                if (! PrivateFile::exists($file->file_path)) {
                    continue;
                }
                // Strip path separators so a crafted upload like "../foo.pdf"
                // cannot escape its folder.
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

                $entries[] = [$folder.'/'.$uniqueName, $file->file_path];
            }
        }

        return $entries;
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
     * punctuation all survive — the ZIP stores UTF-8 entry names, and the
     * filename is RFC 5987-encoded into Content-Disposition.
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
            abort(403, Course::NOT_A_TEACHER_MESSAGE);
        }

        if ($material->type !== Material::TYPE_ASSIGNMENT) {
            abort(404);
        }
    }
}
