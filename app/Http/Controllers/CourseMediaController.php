<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Section;
use App\Support\CourseMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Images and video embedded in course rich text.
 *
 * These used to live on the public disk, which meant the URL saved into the
 * editor's HTML *was* the file — permanent, unauthenticated, and valid for
 * anyone it was ever forwarded to.
 *
 * Now the saved URL points here. It is stable and permanent, but grants
 * nothing on its own: every view is authorised, and only then does the caller
 * receive a short-lived signed URL to the object itself.
 *
 * Note the redirect rather than a stream. Moodle's pluginfile.php pushes every
 * byte through PHP — correct, but it holds a worker for the whole transfer,
 * which is ruinous for video on one vCPU. (Moodle installations at scale hit
 * this too, and tool_objectfs solves it the same way: redirect to a signed
 * object-store URL.) Here PHP authorises and then gets out of the way; the
 * bytes go browser-to-R2 and never touch a PHP process.
 */
class CourseMediaController extends Controller
{
    /** Signed URLs are short-lived; a page open for longer just re-requests. */
    private const URL_TTL_MINUTES = 15;

    private const VIDEO_EXT = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
    ];

    /**
     * Video was previously uncapped, which was never really true — the
     * proxied path is bounded by Cloudflare (100 MB), nginx and PHP, and a
     * teacher exceeding those got an opaque failure rather than a message.
     * Direct-to-R2 lifts those, so this is now the only limit and it needs to
     * be a real number. Roughly an hour of 720p.
     */
    public const MAX_VIDEO_MB = 500;

    /**
     * Serve one media file, if the caller may see the course.
     *
     * Authorisation is CoursePolicy::view — the same check as opening the
     * course page. A file embedded in a lesson should be exactly as visible as
     * the lesson containing it, and reusing the policy means the two can never
     * drift apart.
     */
    public function show(Request $request, Course $course, string $folder, string $file): RedirectResponse|StreamedResponse
    {
        $this->authorize('view', $course);

        // The route constrains both, but they become part of a storage path,
        // so they are validated here rather than trusted.
        if (! in_array($folder, CourseMedia::SERVED_FOLDERS, true)
            || ! preg_match('/^[A-Za-z0-9\-]+\.[A-Za-z0-9]+$/', $file)) {
            abort(404);
        }

        $path = CourseMedia::folder($course->id)."/{$folder}/{$file}";

        if (! CourseMedia::exists($path)) {
            abort(404);
        }

        $disk = CourseMedia::disk();

        if (config('filesystems.disks.'.$disk.'.driver') === 's3') {
            // Byte-range requests pass through the redirect, which is what
            // makes seeking work in a <video> element.
            return redirect()->away(
                Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(self::URL_TTL_MINUTES))
            );
        }

        // The local disk cannot sign, so dev streams instead. Acceptable only
        // because dev serves one person.
        return CourseMedia::response($path, $file, $this->mimeFor($file), 'inline');
    }

    public function uploadImage(Request $request, Course $course): JsonResponse
    {
        $this->authorizeUpload($course);

        $request->validate([
            // Matches the site-wide rule for admin image uploads.
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        // store() re-encodes to WebP and names the result itself.
        $path = CourseMedia::store($request->file('image'), CourseMedia::materialsFolder($course->id));

        return $this->urlFor($course, basename($path));
    }

    /**
     * Step 1 of a direct-to-R2 video upload: a URL the browser can PUT to.
     *
     * Lesson video is the one upload here big enough to matter. Proxied
     * through PHP it would occupy a worker for the whole transfer and die at
     * Cloudflare's 100 MB ceiling; this keeps both out of the way.
     */
    public function presignVideo(Request $request, Course $course): JsonResponse
    {
        $this->authorizeUpload($course);

        $maxBytes = self::MAX_VIDEO_MB * 1024 * 1024;

        $validated = $request->validate([
            'size' => ['required', 'integer', 'min:1', "max:{$maxBytes}"],
            'content_type' => ['required', 'string', Rule::in(array_keys(self::VIDEO_EXT))],
        ], [
            'size.max' => 'Videos must be under '.self::MAX_VIDEO_MB.'MB.',
            'content_type.in' => 'Only MP4, WebM and QuickTime video are allowed.',
        ]);

        $name = Str::uuid().'.'.self::VIDEO_EXT[$validated['content_type']];
        $key = CourseMedia::materialsFolder($course->id).'/'.$name;

        $signed = CourseMedia::presignPut($key, $validated['size'], $validated['content_type']);

        return response()->json([
            'name' => $name,
            'url' => $signed['url'],
            // Host is browser-controlled and cannot be overridden by fetch/XHR.
            'headers' => Arr::except($signed['headers'], ['Host', 'host']),
        ]);
    }

    /**
     * Step 2: the browser reports a successful PUT and we decide whether to
     * keep it.
     *
     * R2 signs only the host, so the size and type declared at presign time
     * constrain nothing (verified against a live bucket — see
     * PrivateFile::presignPut). This is where they are actually enforced, on
     * the object that really landed, and anything rejected is deleted rather
     * than left occupying the bucket.
     */
    public function registerVideo(Request $request, Course $course): JsonResponse
    {
        $this->authorizeUpload($course);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9\-]+\.[A-Za-z0-9]+$/'],
        ]);

        $name = $validated['name'];
        $key = CourseMedia::materialsFolder($course->id).'/'.$name;

        if (! CourseMedia::exists($key)) {
            return response()->json(['message' => 'Upload not found — please try again.'], 404);
        }

        $size = CourseMedia::sizeOf($key);

        if ($size > self::MAX_VIDEO_MB * 1024 * 1024) {
            CourseMedia::forget($key);

            return response()->json(['message' => 'Videos must be under '.self::MAX_VIDEO_MB.'MB.'], 422);
        }

        // The declared Content-Type is a claim. Sniff the stored bytes.
        $mime = CourseMedia::sniffMimeType($key);

        if (! array_key_exists($mime, self::VIDEO_EXT)) {
            CourseMedia::forget($key);

            return response()->json(['message' => 'That file is not a supported video.'], 422);
        }

        return $this->urlFor($course, $name);
    }

    /**
     * Proxied upload — the fallback, kept for the same reasons submissions
     * keep theirs: a network that blocks the R2 endpoint would otherwise leave
     * a teacher unable to upload anything at all.
     *
     * Bounded by Cloudflare, nginx and PHP, so it handles small clips but not
     * a full lesson. The direct path is preferred whenever it works.
     */
    public function uploadVideo(Request $request, Course $course): JsonResponse
    {
        $this->authorizeUpload($course);

        $request->validate([
            'video' => [
                'required', 'file',
                'mimetypes:'.implode(',', array_keys(self::VIDEO_EXT)),
                'max:'.(self::MAX_VIDEO_MB * 1024),
            ],
        ], [
            'video.max' => 'Videos must be under '.self::MAX_VIDEO_MB.'MB.',
        ]);

        $upload = $request->file('video');

        // Extension from the sniffed MIME type, never the client's filename.
        $name = Str::uuid().'.'.(self::VIDEO_EXT[$upload->getMimeType()] ?? 'mp4');

        CourseMedia::storeAs($upload, CourseMedia::materialsFolder($course->id), $name);

        return $this->urlFor($course, $name);
    }

    /**
     * SectionPolicy::create requires both the sections.manage permission AND
     * that the user teaches this course.
     *
     * The endpoints this replaces took no course at all and checked only the
     * permission, so any teacher holding it could upload media unattached to
     * anything they actually run.
     */
    private function authorizeUpload(Course $course): void
    {
        $this->authorize('create', [Section::class, $course]);
    }

    private function urlFor(Course $course, string $name): JsonResponse
    {
        return response()->json([
            'url' => route('course-media.show', [
                'course' => $course->id,
                'folder' => 'materials',
                'file' => $name,
            ]),
        ]);
    }

    private function mimeFor(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => 'application/octet-stream',
        };
    }
}
