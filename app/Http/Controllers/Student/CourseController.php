<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Section;
use App\Support\Cache\CacheKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        }

        if ($user->teaches($course)) {
            // Teachers now live in the same enrollments table (role='teacher').
            // updateExistingPivot respects the wherePivot('role_on_course','teacher')
            // constraint we set on Course::teachers(), so this only touches the
            // teacher row even if the user also has a student row for this course.
            $course->teachers()
                ->updateExistingPivot($user->id, ['last_accessed_at' => now()]);
        }

        // Universal "I opened this course" record — works for admins too
        // (who have no enrollments row of either role) and is the single
        // source for the Home page's Recently Accessed strip.
        \DB::table('course_views')->upsert(
            [[
                'user_id' => $user->id,
                'course_id' => $course->id,
                'accessed_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]],
            ['user_id', 'course_id'],
            ['accessed_at', 'updated_at']
        );

        // Recently-accessed widgets read from cache; bust both so this
        // visit shows up immediately on the next render.
        Cache::forget(CacheKeys::userRecent($user->id));
        Cache::forget(CacheKeys::userEnrolled($user->id));

        // Per-user section fold state. Persists across devices — see
        // toggleFold() below and 2026_08_11_140000_create_user_collapsed_sections_table.
        $collapsedSectionIds = DB::table('user_collapsed_sections')
            ->where('user_id', $user->id)
            ->whereIn('section_id', $cached->sections->pluck('id'))
            ->pluck('section_id')
            ->all();

        return view('student.courses.show', [
            'course' => $cached,
            'canManage' => $canManage,
            'collapsedSectionIds' => $collapsedSectionIds,
        ]);
    }

    /**
     * Flip the fold state for one section for the current user.
     *   - Row missing (default open) → insert = now collapsed
     *   - Row exists (collapsed)     → delete = now open again
     *
     * Fire-and-forget from the client. Response tells the caller the new
     * state so they can reconcile if needed.
     */
    public function toggleFold(Request $request, Section $section): JsonResponse
    {
        $userId = $request->user()->id;

        $existing = DB::table('user_collapsed_sections')
            ->where('user_id', $userId)
            ->where('section_id', $section->id)
            ->exists();

        if ($existing) {
            DB::table('user_collapsed_sections')
                ->where('user_id', $userId)
                ->where('section_id', $section->id)
                ->delete();
            return response()->json(['collapsed' => false]);
        }

        DB::table('user_collapsed_sections')->insert([
            'user_id' => $userId,
            'section_id' => $section->id,
            'created_at' => now(),
        ]);

        return response()->json(['collapsed' => true]);
    }
}
