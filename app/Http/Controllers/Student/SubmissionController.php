<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Support\PrivateFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    /**
     * Student uploads one or more files to an assignment.
     *
     * Hard-block once past the due date. Enforces the per-assignment file
     * count and per-file size cap the teacher configured.
     */
    public function upload(Request $request, Material $material): RedirectResponse
    {
        $this->assertIsAssignment($material);
        $this->authorize('download', $material);

        if ($material->isPastDue()) {
            return back()->withErrors(['files' => 'Submissions are closed for this assignment.']);
        }

        $maxGb = $material->max_file_size_gb ?? 1;
        $maxSizeKb = $maxGb * 1024 * 1024;
        $maxFiles = $material->max_files ?? 5;

        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => [
                'file',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
                "max:{$maxSizeKb}",
            ],
        ], [
            'files.*.mimetypes' => 'Only PDF and image files (jpg/png/webp) are allowed.',
            'files.*.max' => "Each file must be under {$maxGb}GB.",
        ]);

        $user = $request->user();
        $uploads = $request->file('files');

        // Cheap pre-check so an obviously over-cap upload is rejected before
        // anything is written. Re-checked authoritatively inside the
        // transaction below, which is what actually enforces the limit.
        $existing = Submission::where('material_id', $material->id)
            ->where('user_id', $user->id)
            ->withCount('files')
            ->first()?->files_count ?? 0;

        if ($existing + count($uploads) > $maxFiles) {
            return back()->withErrors(['files' => "This assignment allows at most {$maxFiles} files. You already have {$existing}."]);
        }

        // --- 1. Write the files FIRST, outside any transaction. ---
        //
        // This is the slow part: on the production disk it's an upload to R2,
        // seconds per file. Doing it inside a transaction would hold a write
        // lock for that whole time — on SQLite that's a lock on the entire
        // database, so every other student's course page (which writes
        // course_views and enrollments) would block until this finished.
        // Measured at 6ms -> 2600ms for concurrent page views.
        $courseId = $material->section->course_id;
        $stored = [];

        try {
            foreach ($uploads as $upload) {
                $ext = strtolower($upload->getClientOriginalExtension() ?: 'bin');
                $name = Str::uuid().'.'.$ext;

                // PrivateFile: student work must never be reachable by URL,
                // and must land byte-for-byte as uploaded (no re-encoding).
                $stored[] = [
                    'file_path' => PrivateFile::storeAs($upload, "submissions/{$courseId}/{$material->id}/{$user->id}", $name),
                    'original_name' => $upload->getClientOriginalName(),
                    'size_bytes' => $upload->getSize(),
                    'mime_type' => $upload->getMimeType(),
                    'uploaded_at' => now(),
                ];
            }

            // --- 2. Now a short transaction for the rows only. ---
            DB::transaction(function () use ($material, $user, $maxFiles, $stored) {
                $submission = Submission::firstOrCreate(
                    ['material_id' => $material->id, 'user_id' => $user->id],
                    ['submitted_at' => now()],
                );

                // Authoritative check — two uploads racing could both pass the
                // pre-check above, so the cap is enforced here where it's
                // serialised against other writers.
                if ($submission->files()->count() + count($stored) > $maxFiles) {
                    abort(422, "This assignment allows at most {$maxFiles} files.");
                }

                $submission->files()->createMany($stored);
            });
        } catch (\Throwable $e) {
            // --- 3. Rows didn't land, so the files shouldn't either. ---
            // Preserves the original all-or-nothing guarantee without holding
            // a lock across the uploads.
            foreach ($stored as $row) {
                PrivateFile::forget($row['file_path']);
            }

            throw $e;
        }

        return redirect()
            ->route('materials.view', $material)
            ->with('status', 'Uploaded.');
    }

    /**
     * Student removes one of their own submission files.
     * Hard-blocked past the due date (same as uploads).
     */
    public function destroyFile(Request $request, SubmissionFile $file): RedirectResponse
    {
        $submission = $file->submission;
        $material = $submission->material;

        if ($submission->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($material->isPastDue()) {
            return back()->withErrors(['files' => 'Submissions are closed — files cannot be removed.']);
        }

        PrivateFile::forget($file->file_path);
        $file->delete();

        return redirect()
            ->route('materials.view', $material)
            ->with('status', 'File removed.');
    }

    /**
     * Stream a submission file for download. Authorization:
     *   - the student who owns the submission, OR
     *   - a teacher of the course containing the assignment.
     */
    public function download(Request $request, SubmissionFile $file): StreamedResponse
    {
        $submission = $file->submission;
        $material = $submission->material;
        $user = $request->user();

        $isOwner = $submission->user_id === $user->id;
        $isTeacher = $user->teaches($material->section->course);

        if (! $isOwner && ! $isTeacher && ! $user->hasRole('admin')) {
            abort(403);
        }

        if (! PrivateFile::exists($file->file_path)) {
            abort(404);
        }

        // Authorised above — PrivateFile::response performs no checks itself.
        return PrivateFile::response(
            $file->file_path,
            $file->original_name,
            $file->mime_type ?? 'application/octet-stream',
        );
    }

    private function assertIsAssignment(Material $material): void
    {
        if ($material->type !== Material::TYPE_ASSIGNMENT) {
            abort(404);
        }
    }
}
