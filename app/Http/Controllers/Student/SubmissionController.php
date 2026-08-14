<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Support\PrivateFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    /**
     * Filename extension per accepted type. Derived from the signed
     * Content-Type rather than the client's filename, so a student cannot
     * choose the extension of the object we store.
     */
    private const EXT_BY_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Step 1 of a direct-to-R2 upload: hand the browser a URL it can PUT one
     * file to without the bytes passing through this server.
     *
     * The checks here — due date, per-file size, declared type, file-count cap
     * — filter honest clients early so a doomed upload never starts. None of
     * them bind a dishonest one: R2 signs only the host, so the size and type
     * passed to presignPut() are advisory (verified against a real bucket; see
     * PrivateFile::presignPut). A client can PUT any bytes it likes to the URL
     * it is given.
     *
     * register() is therefore not a second opinion, it is the only opinion.
     */
    public function presign(Request $request, Material $material): JsonResponse
    {
        $this->assertIsAssignment($material);
        $this->authorize('download', $material);

        if ($material->isPastDue()) {
            return response()->json(['message' => 'Submissions are closed for this assignment.'], 422);
        }

        $maxBytes = $material->maxFileSizeBytes();
        $maxMb = $material->max_file_size_mb ?: Material::DEFAULT_MAX_FILE_SIZE_MB;

        $validated = $request->validate([
            'size' => ['required', 'integer', 'min:1', "max:{$maxBytes}"],
            'content_type' => ['required', 'string', Rule::in(Material::SUBMISSION_MIME_TYPES)],
        ], [
            'size.max' => "Each file must be under {$maxMb}MB.",
            'content_type.in' => 'Only PDF and image files (jpg/png/webp) are allowed.',
        ]);

        $user = $request->user();
        $maxFiles = $material->maxFiles();

        // Cheap pre-check. The authoritative one is in register(), where it's
        // serialised against other writers.
        $existing = $this->fileCountFor($material, $user->id);

        if ($existing >= $maxFiles) {
            return response()->json([
                'message' => "This assignment allows at most {$maxFiles} files. You already have {$existing}.",
            ], 422);
        }

        $ext = self::EXT_BY_MIME[$validated['content_type']];
        $key = $this->submissionPrefix($material, $user->id).'/'.Str::uuid().'.'.$ext;

        $signed = PrivateFile::presignPut($key, $validated['size'], $validated['content_type']);

        return response()->json([
            'key' => $key,
            'url' => $signed['url'],
            // Host is set by the browser and cannot be overridden by fetch();
            // sending it back would only cause the request to be rejected.
            'headers' => Arr::except($signed['headers'], ['Host', 'host']),
        ]);
    }

    /**
     * Step 2: the browser reports a successful PUT, and we decide whether to
     * accept it.
     *
     * This is where the guarantees actually live. The object exists in the
     * bucket by now, so every rejection below also deletes it — otherwise a
     * failed submission would leave bytes behind that nothing references.
     */
    public function register(Request $request, Material $material): JsonResponse
    {
        $this->assertIsAssignment($material);
        $this->authorize('download', $material);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:512'],
            'original_name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $key = $validated['key'];
        $prefix = $this->submissionPrefix($material, $user->id).'/';

        // Binds the object to this student AND this assignment. Without it a
        // student could presign under their own prefix, then register a key
        // belonging to someone else and claim their work.
        if (! str_starts_with($key, $prefix) || str_contains($key, '..')) {
            abort(403);
        }

        if (! PrivateFile::exists($key)) {
            return response()->json(['message' => 'Upload not found — please try again.'], 404);
        }

        // Presign and PUT are two requests, so a deadline can pass between
        // them. The short TTL narrows the window; this closes it.
        if ($material->isPastDue()) {
            PrivateFile::forget($key);

            return response()->json(['message' => 'Submissions are closed for this assignment.'], 422);
        }

        $size = PrivateFile::sizeOf($key);

        if ($size > $material->maxFileSizeBytes()) {
            PrivateFile::forget($key);
            $maxMb = $material->max_file_size_mb ?: Material::DEFAULT_MAX_FILE_SIZE_MB;

            return response()->json(['message' => "Each file must be under {$maxMb}MB."], 422);
        }

        // The client's declared Content-Type is a claim, not evidence. Sniff
        // the stored bytes — the same magic-byte check the `mimetypes:` rule
        // performed back when uploads went through PHP.
        $mime = PrivateFile::sniffMimeType($key);

        if (! in_array($mime, Material::SUBMISSION_MIME_TYPES, true)) {
            PrivateFile::forget($key);

            return response()->json(['message' => 'Only PDF and image files (jpg/png/webp) are allowed.'], 422);
        }

        try {
            DB::transaction(function () use ($material, $user, $key, $size, $mime, $validated) {
                $submission = Submission::firstOrCreate(
                    ['material_id' => $material->id, 'user_id' => $user->id],
                    ['submitted_at' => now()],
                );

                $maxFiles = $material->maxFiles();

                if ($submission->files()->count() + 1 > $maxFiles) {
                    abort(422, "This assignment allows at most {$maxFiles} files.");
                }

                $submission->files()->create([
                    'file_path' => $key,
                    'original_name' => $this->safeName($validated['original_name']),
                    'size_bytes' => $size,
                    'mime_type' => $mime,
                    'uploaded_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            // Lost the cap race, or the insert failed. Either way the row
            // isn't there, so the object shouldn't be either.
            PrivateFile::forget($key);

            throw $e;
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Fallback upload path: the file is posted here and this server forwards
     * it to storage.
     *
     * Two cases still need it. Local dev runs on the `local` disk, which
     * cannot presign at all. And in production a school or corporate network
     * may block the R2 endpoint outright while allowing this site — without
     * this path, those students would have no way to submit anything.
     *
     * It carries the caps that Cloudflare (100MB), nginx and PHP impose on a
     * proxied request, so it handles ordinary homework but not large files.
     * The direct path is preferred whenever it works.
     */
    public function upload(Request $request, Material $material): RedirectResponse
    {
        $this->assertIsAssignment($material);
        $this->authorize('download', $material);

        if ($material->isPastDue()) {
            return back()->withErrors(['files' => 'Submissions are closed for this assignment.']);
        }

        $maxMb = $material->max_file_size_mb ?: Material::DEFAULT_MAX_FILE_SIZE_MB;
        $maxSizeKb = $maxMb * 1024;
        $maxFiles = $material->maxFiles();

        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => [
                'file',
                'mimetypes:'.implode(',', Material::SUBMISSION_MIME_TYPES),
                "max:{$maxSizeKb}",
            ],
        ], [
            'files.*.mimetypes' => 'Only PDF and image files (jpg/png/webp) are allowed.',
            'files.*.max' => "Each file must be under {$maxMb}MB.",
        ]);

        $user = $request->user();
        $uploads = $request->file('files');

        // Cheap pre-check so an obviously over-cap upload is rejected before
        // anything is written. Re-checked authoritatively inside the
        // transaction below, which is what actually enforces the limit.
        $existing = $this->fileCountFor($material, $user->id);

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
        // Same prefix the direct path uses, so both kinds of upload live
        // together and the orphan sweep only has one shape to reason about.
        $folder = $this->submissionPrefix($material, $user->id);
        $stored = [];

        try {
            foreach ($uploads as $upload) {
                $ext = self::EXT_BY_MIME[$upload->getMimeType()] ?? 'bin';
                $name = Str::uuid().'.'.$ext;

                // PrivateFile: student work must never be reachable by URL,
                // and must land byte-for-byte as uploaded (no re-encoding).
                $stored[] = [
                    'file_path' => PrivateFile::storeAs($upload, $folder, $name),
                    'original_name' => $this->safeName($upload->getClientOriginalName()),
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
     * Hand over a submission file. Authorization:
     *   - the student who owns the submission, OR
     *   - a teacher of the course containing the assignment.
     *
     * Redirects to a signed URL rather than streaming. Streaming held a
     * PHP-FPM worker for the whole transfer, which is fine for a student
     * fetching their own file and not fine for a teacher opening thirty in a
     * row during marking — on eight workers that is the whole pool.
     */
    public function download(Request $request, SubmissionFile $file): RedirectResponse|StreamedResponse
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

        // Authorised above — PrivateFile::download performs no checks itself.
        return PrivateFile::download(
            $file->file_path,
            $file->original_name,
            $file->mime_type,
        );
    }

    private function assertIsAssignment(Material $material): void
    {
        if ($material->type !== Material::TYPE_ASSIGNMENT) {
            abort(404);
        }
    }

    /**
     * Storage prefix for one student's work on one assignment.
     *
     * Doubles as an authorization boundary: register() only accepts keys that
     * start with this, so an object can never be claimed by a student it
     * wasn't signed for.
     */
    private function submissionPrefix(Material $material, int $userId): string
    {
        return "submissions/{$material->section->course_id}/{$material->id}/{$userId}";
    }

    private function fileCountFor(Material $material, int $userId): int
    {
        return Submission::where('material_id', $material->id)
            ->where('user_id', $userId)
            ->withCount('files')
            ->first()?->files_count ?? 0;
    }

    /**
     * The original filename is shown back to students and teachers, so strip
     * anything path-like or non-printable before it's stored.
     */
    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return trim($name) !== '' ? Str::limit($name, 255, '') : 'submission';
    }
}
