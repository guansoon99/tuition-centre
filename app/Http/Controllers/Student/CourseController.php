<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Support\Cache\CacheKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class CourseController extends Controller
{
    public function show(Request $request, Course $course): View
    {
        $this->authorize('view', $course);

        $user = $request->user();
        $canManage = $user->can('manageContent', $course);

        // Auto-publish any sections whose scheduled release time has passed,
        // then bust the cached course detail so the change is reflected.
        if ($course->releaseScheduledSections() > 0) {
            Cache::forget(CacheKeys::courseDetail($course->id));
        }

        $cached = Cache::remember(
            CacheKeys::courseDetail($course->id),
            CacheKeys::TTL_COURSE_DETAIL,
            fn () => $course->load(['sections.materials'])
        );

        if ($user->hasRole('student')) {
            $user->enrollments()
                ->where('course_id', $course->id)
                ->update(['last_accessed_at' => now()]);

            Cache::forget(CacheKeys::userRecent($user->id));
        }

        if ($user->hasRole('teacher')) {
            $course->teachers()
                ->updateExistingPivot($user->id, ['last_accessed_at' => now()]);
        }

        return view('student.courses.show', [
            'course' => $cached,
            'canManage' => $canManage,
        ]);
    }
}
