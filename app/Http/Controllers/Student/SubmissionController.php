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

        DB::transaction(function () use ($request, $material, $user, $maxFiles) {
            $submission = Submission::firstOrCreate(
                ['material_id' => $material->id, 'user_id' => $user->id],
                ['submitted_at' => now()],
            );

            $existingCount = $submission->files()->count();
            $incomingCount = count($request->file('files'));

            if ($existingCount + $incomingCount > $maxFiles) {
                abort(422, "This assignment allows at most {$maxFiles} files. You already have {$existingCount}.");
            }

            $courseId = $material->section->course_id;

            foreach ($request->file('files') as $upload) {
                $ext = strtolower($upload->getClientOriginalExtension() ?: 'bin');
                $name = Str::uuid().'.'.$ext;
                // PrivateFile: student work must never be reachable by URL,
                // and must land byte-for-byte as uploaded (no re-encoding).
                $path = PrivateFile::storeAs($upload, "submissions/{$courseId}/{$material->id}/{$user->id}", $name);

                $submission->files()->create([
                    'file_path' => $path,
                    'original_name' => $upload->getClientOriginalName(),
                    'size_bytes' => $upload->getSize(),
                    'mime_type' => $upload->getMimeType(),
                    'uploaded_at' => now(),
                ]);
            }
        });

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
