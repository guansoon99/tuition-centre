<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourseRequest;
use App\Models\Course;
use App\Models\Material;
use App\Models\Section;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Support\Cache\CacheKeys;
use App\Support\PrivateFile;
use App\Support\PublicFile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CourseController extends Controller
{
    public function index(Request $request): View
    {
        // Route middleware already enforces courses.view — anyone who reaches
        // this action is meant to see every course, active or not. (visibleTo
        // is used by the student-facing widgets, which need is_active=true.)
        $query = Course::query()
            ->withCount(['teachers', 'students', 'sections']);

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $courses = $query->orderByDesc('created_at')->paginate(25)->withQueryString();

        return view('admin.courses.index', [
            'courses' => $courses,
            'filters' => $request->only(['q', 'active']),
        ]);
    }

    public function create(): View
    {
        return view('admin.courses.create');
    }

    public function store(CourseRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if ($request->hasFile('banner_image')) {
            $data['banner_image'] = PublicFile::store($request->file('banner_image'), 'course-banners');
        }

        $data['is_active'] = $request->boolean('is_active', true);
        $data['slug'] = Str::slug($data['code']);

        $course = Course::create($data);

        return redirect()
            ->route('courses.edit', $course)
            ->with('status', "Course {$course->code} created.");
    }

    public function edit(Request $request, Course $course): View
    {
        // Admins and users with the courses.view permission (course managers)
        // can open the edit page for any course, active or not. Teachers
        // without courses.view can only open ACTIVE courses they teach.
        $user = $request->user();
        if (! $user->hasRole('admin') && ! $user->can('courses.view')) {
            abort_unless($user->teaches($course) && $course->is_active, 403);
        }

        // If a specific tab was requested via ?tab=X, verify the user has the
        // matching permission — otherwise 403 (instead of loading the page
        // and rendering nothing because the tab's section is @if-guarded).
        $requestedTab = $request->string('tab')->value();
        $tabPermissions = [
            'details' => 'courses.manage_details',
            'teachers' => 'courses.manage_teachers',
            'students' => 'courses.manage_students',
            'materials' => 'sections.manage',
        ];
        if ($requestedTab && isset($tabPermissions[$requestedTab])) {
            abort_unless($user->can($tabPermissions[$requestedTab]), 403);
        }

        // Auto-publish any sections whose scheduled release time has passed.
        $course->releaseScheduledSections();

        $course->load(['teachers', 'students', 'sections.materials']);

        // Anyone who isn't a student or admin (system roles) can be assigned
        // as a course teacher. Their ability to actually edit content
        // afterwards depends on whatever permissions their role has —
        // assignment is just the pivot record; sections.manage gates editing.
        $teacherCandidates = \App\Models\User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['admin', 'student']))
            ->whereNotIn('id', $course->teachers->pluck('id'))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'username', 'name']);

        $studentCandidates = \App\Models\User::role('student')
            ->where('is_active', true)
            ->whereNotIn('id', $course->students->pluck('id'))
            ->orderBy('username')
            ->limit(200)
            ->get(['id', 'username', 'name']);

        // Student enrollment rows for the Students tab. whereHas('user')
        // drops rows whose student has been soft deleted — the enrollment
        // survives (nothing cascades on a soft delete) but ->user resolves
        // to null, which the table would fatal on.
        $enrollments = $course->enrollments()
            ->whereHas('user')
            ->with('user')
            ->orderByDesc('enrolled_at')
            ->get();

        return view('admin.courses.edit', [
            'course' => $course,
            'teacherCandidates' => $teacherCandidates,
            'studentCandidates' => $studentCandidates,
            'enrollments' => $enrollments,
        ]);
    }

    public function update(CourseRequest $request, Course $course): RedirectResponse
    {
        $data = $request->validated();

        if ($request->hasFile('banner_image')) {
            if ($course->banner_image) {
                PublicFile::forget($course->banner_image);
            }
            $data['banner_image'] = PublicFile::store($request->file('banner_image'), 'course-banners');
        } else {
            unset($data['banner_image']);
        }

        $data['is_active'] = $request->boolean('is_active');
        $data['slug'] = Str::slug($data['code']);

        $course->update($data);

        return redirect()
            ->route('courses.edit', $course)
            ->with('status', 'Course updated.');
    }

    public function destroy(Course $course): RedirectResponse
    {
        $course->update(['is_active' => false]);
        $this->bustCourseCaches($course);

        return redirect()
            ->route('courses.index')
            ->with('status', "Course {$course->code} deactivated.");
    }

    /**
     * Permanent bulk delete — admin only. Hard-deletes the selected courses
     * and lets the FK chain clear everything below them:
     *
     *   courses → sections → materials → submissions → submission_files
     *   courses → enrollments / course_views / announcements / access_logs
     *
     * This MUST be forceDelete(), not delete(). Course uses SoftDeletes, and
     * a soft delete emits `UPDATE courses SET deleted_at = ...` — an UPDATE,
     * which fires no ON DELETE CASCADE at all. Every child row would stay
     * live while we removed their files, and the course would go on squatting
     * its unique slug/code so the admin could never reuse that code.
     *
     * Order matters: caches and file paths are both read from rows the delete
     * destroys, so they're gathered first. Files are unlinked only after the
     * transaction commits — if the delete fails we want the files still on
     * disk (recoverable) rather than gone with the rows intact.
     *
     * Not reversible — grades and submissions go too. The UI wraps this in a
     * strong confirmation.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        // Route middleware already gates on 'courses.delete' permission.
        // Defence in depth: re-check here in case the route is ever hit
        // through a different middleware stack (queued jobs, tests, etc).
        abort_unless($request->user()->can('courses.delete'), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:courses,id'],
        ]);

        $courses = Course::whereIn('id', $data['ids'])->get();
        if ($courses->isEmpty()) {
            return redirect()->back(fallback: route('courses.index'));
        }
        $courseIds = $courses->pluck('id')->all();

        // --- 1. Bust caches while the membership rows still exist. ---
        foreach ($courses as $course) {
            $this->bustCourseCaches($course);
        }

        // --- 2. Collect every file path the cascade is about to orphan. ---
        // withTrashed() throughout: a soft-deleted section or material is
        // still a real row that the cascade will remove, and its file is
        // still on disk. Miss it here and the row vanishes with the only
        // reference to that file — it leaks forever.
        $bannerPaths = $courses->pluck('banner_image')->filter()->all();

        $sectionIds = Section::withTrashed()
            ->whereIn('course_id', $courseIds)
            ->pluck('id');

        $materialIds = Material::withTrashed()
            ->whereIn('section_id', $sectionIds)
            ->pluck('id');

        $materialPaths = Material::withTrashed()
            ->whereIn('id', $materialIds)
            ->whereNotNull('file_path')
            ->pluck('file_path')
            ->all();

        $submissionPaths = SubmissionFile::whereIn(
            'submission_id',
            Submission::whereIn('material_id', $materialIds)->select('id')
        )
            ->whereNotNull('file_path')
            ->pluck('file_path')
            ->all();

        // --- 3. Drop the rows. The FK chain clears everything downstream. ---
        DB::transaction(function () use ($courseIds) {
            Course::whereIn('id', $courseIds)->forceDelete();
        });

        // --- 4. DB is committed — now free the disk. ---
        // Banners live on the public uploads disk; materials and submissions
        // are private and live on the default disk.
        foreach ($bannerPaths as $path) {
            PublicFile::forget($path);
        }

        foreach ([...$materialPaths, ...$submissionPaths] as $path) {
            PrivateFile::forget($path);
        }

        // Return to the exact filtered URL the admin was on — Laravel's
        // back() uses the session's previous URL, which is the /courses
        // page (with its ?q=&active=… query string) that submitted this POST.
        return redirect()->back(fallback: route('courses.index'));
    }

    public function activate(Course $course): RedirectResponse
    {
        $course->update(['is_active' => true]);
        $this->bustCourseCaches($course);

        return redirect()
            ->route('courses.index')
            ->with('status', "Course {$course->code} activated.");
    }

    private function bustCourseCaches(Course $course): void
    {
        // Every user linked to this course (student OR teacher) sees it
        // through Course::visibleTo(), which is cached under userEnrolled
        // on the Home page. One pass over all memberships covers both.
        foreach ($course->courseMemberships()->pluck('user_id')->unique() as $userId) {
            Cache::forget(CacheKeys::userEnrolled($userId));
            Cache::forget(CacheKeys::userRecent($userId));
            Cache::forget(CacheKeys::userCourseMemberships($userId));
        }
    }
}
