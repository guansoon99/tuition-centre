<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\FeedbackFile;
use App\Models\Material;
use App\Models\Submission;
use App\Support\CourseMedia;
use App\Support\PrivateFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files a teacher returns to one student.
 *
 * Proxied through PHP rather than presigned, unlike student submissions. That
 * chain exists because a student's phone scan or video can exceed Cloudflare's
 * 100MB proxy cap; feedback is a marked-up copy of work already accepted, so
 * it is bounded by a document that already came through this same path. If
 * that stops being true, presigning is the change to make — not a bigger cap.
 */
class FeedbackFileController extends Controller
{
    /** Matches the ceiling on the work being marked. */
    private const MAX_MB = 50;

    public function store(Request $request, Submission $submission): RedirectResponse|JsonResponse|Response
    {
        $material = $submission->material;
        $this->assertMayGrade($request, $material);

        // Feedback is a response to work. Without a submission there is
        // nothing to respond to, and the student has no page section to
        // show it in.
        if (! $submission->hasSubmittedWork()) {
            return $this->refuse($request, 'This student has not submitted anything yet.');
        }

        $request->validate([
            'feedback_files' => ['required', 'array', 'min:1'],
            'feedback_files.*' => [
                'file',
                'mimetypes:'.implode(',', Material::sniffableSubmissionMimeTypes()),
                'max:'.(self::MAX_MB * 1024),
            ],
        ], [
            'feedback_files.*.mimetypes' => 'Only PDF, image (jpg/png/webp), Word or PowerPoint files are allowed.',
            'feedback_files.*.max' => 'Each file must be under '.self::MAX_MB.'MB.',
        ]);

        $folder = CourseMedia::feedbackFolder(
            $material->section->course_id,
            $material->id,
            $submission->user_id,
        );

        $stored = [];

        try {
            foreach ($request->file('feedback_files') as $upload) {
                // Same two-part decision the student path makes: sniffed bytes
                // plus the extension, because Word and PowerPoint cannot be
                // told apart from bytes alone.
                $mime = Material::resolveSubmissionMime(
                    $upload->getMimeType(),
                    $upload->getClientOriginalExtension(),
                );

                if ($mime === null) {
                    return $this->refuse(
                        $request,
                        'Only PDF, image (jpg/png/webp), Word or PowerPoint files are allowed.',
                    );
                }

                $ext = strtolower($upload->getClientOriginalExtension()) ?: 'bin';

                $stored[] = [
                    'file_path' => PrivateFile::storeAs($upload, $folder, Str::uuid().'.'.$ext),
                    'original_name' => $this->safeName($upload->getClientOriginalName()),
                    'size_bytes' => $upload->getSize(),
                    'mime_type' => $mime,
                    'uploaded_at' => now(),
                    'uploaded_by_user_id' => $request->user()->id,
                ];
            }

            $submission->feedbackFiles()->createMany($stored);
        } catch (\Throwable $e) {
            // Rows did not land, so the objects should not either.
            foreach ($stored as $row) {
                PrivateFile::forget($row['file_path']);
            }

            throw $e;
        }

        // The grading modal posts this over fetch and stays open, so it wants
        // an answer rather than a page. A redirect here would be followed by
        // fetch and pull the whole roster back for nothing.
        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect()
            ->route('materials.view', $material)
            ->with('status', 'Feedback file uploaded.');
    }

    /** Same refusal, shaped for whichever kind of caller asked. */
    private function refuse(Request $request, string $message): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : back()->withErrors(['feedback' => $message]);
    }

    public function destroy(Request $request, FeedbackFile $file): RedirectResponse|Response
    {
        $material = $file->submission->material;
        $this->assertMayGrade($request, $material);

        PrivateFile::forget($file->file_path);
        $file->delete();

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect()
            ->route('materials.view', $material)
            ->with('status', 'Feedback file removed.');
    }

    /**
     * Hand the file over.
     *
     * Readable by the student it was written for, and by the staff who can
     * see the whole assignment. Redirects to a signed URL on a cloud disk
     * rather than streaming, for the same reason submissions do: streaming
     * holds a worker for the length of the transfer.
     */
    public function download(Request $request, FeedbackFile $file): RedirectResponse|StreamedResponse
    {
        $submission = $file->submission;
        $user = $request->user();

        $isOwner = $submission->user_id === $user->id;
        $isStaff = $user->teaches($submission->material->section->course) || $user->hasRole('admin');

        if (! $isOwner && ! $isStaff) {
            abort(403);
        }

        if (! PrivateFile::exists($file->file_path)) {
            abort(404);
        }

        return PrivateFile::download($file->file_path, $file->original_name, $file->mime_type);
    }

    /** Same rule as grading: staff on this course, and assignments only. */
    private function assertMayGrade(Request $request, Material $material): void
    {
        $user = $request->user();

        if (! $user->teaches($material->section->course) && ! $user->hasRole('admin')) {
            abort(403);
        }

        if ($material->type !== Material::TYPE_ASSIGNMENT) {
            abort(404);
        }
    }

    /** Shown back to a student, so strip anything path-like or non-printable. */
    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[[:cntrl:]]/u', '', $name) ?? '';

        return trim($name) !== '' ? Str::limit($name, 255, '') : 'feedback';
    }
}
