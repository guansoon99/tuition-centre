<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourseRequest;
use App\Models\Course;
use App\Support\Cache\CacheKeys;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
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
            $data['banner_image'] = $request->file('banner_image')->store('course-banners', 'public');
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

        return view('admin.courses.edit', [
            'course' => $course,
            'teacherCandidates' => $teacherCandidates,
            'studentCandidates' => $studentCandidates,
        ]);
    }

    public function update(CourseRequest $request, Course $course): RedirectResponse
    {
        $data = $request->validated();

        if ($request->hasFile('banner_image')) {
            if ($course->banner_image) {
                Storage::disk('public')->delete($course->banner_image);
            }
            $data['banner_image'] = $request->file('banner_image')->store('course-banners', 'public');
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
        Cache::forget(CacheKeys::courseDetail($course->id));

        foreach ($course->teachers()->pluck('users.id') as $teacherId) {
            Cache::forget(CacheKeys::userAssigned($teacherId));
            Cache::forget(CacheKeys::userRecent($teacherId));
        }

        foreach ($course->enrollments()->pluck('user_id') as $studentId) {
            Cache::forget(CacheKeys::userEnrolled($studentId));
            Cache::forget(CacheKeys::userRecent($studentId));
        }
    }
}
