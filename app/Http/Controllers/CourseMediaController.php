<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Section;
use App\Support\CourseMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
     * Serve one media file, if the caller may see the course.
     *
     * Authorisation is CoursePolicy::view — the same check as opening the
     * course page. A file embedded in a lesson should be exactly as visible as
     * the lesson containing it, and reusing the policy means the two can never
     * drift apart.
     */
    public function show(Request $request, Course $course, string $file): RedirectResponse|StreamedResponse
    {
        $this->authorize('view', $course);

        // The route constrains this too, but the value becomes part of a
        // storage path, so it is validated here rather than trusted.
        if (! preg_match('/^[A-Za-z0-9\-]+\.[A-Za-z0-9]+$/', $file)) {
            abort(404);
        }

        $path = CourseMedia::folder($course->id).'/'.$file;

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
        $path = CourseMedia::store($request->file('image'), CourseMedia::folder($course->id));

        return $this->urlFor($course, basename($path));
    }

    public function uploadVideo(Request $request, Course $course): JsonResponse
    {
        $this->authorizeUpload($course);

        $request->validate([
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime'],
        ]);

        $upload = $request->file('video');

        // Extension from the sniffed MIME type, never the client's filename.
        $name = Str::uuid().'.'.(self::VIDEO_EXT[$upload->getMimeType()] ?? 'mp4');

        CourseMedia::storeAs($upload, CourseMedia::folder($course->id), $name);

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
            'url' => route('course-media.show', ['course' => $course->id, 'file' => $name]),
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
