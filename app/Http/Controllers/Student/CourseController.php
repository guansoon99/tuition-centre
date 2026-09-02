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

        // Loaded straight from the DB rather than cached. Benchmarked at a
        // full school-year course (30 sections x 10 materials) the cache saved
        // under 2ms, while costing ~1.4MB of file-cache per course, three
        // observers' worth of invalidation, and a standing risk of serving a
        // stale object graph after a migration. Not a trade worth making —
        // this is three indexed queries.
        $course->load(['sections.materials']);

        // Auto-publish any sections whose scheduled release time has passed.
        // There is no cron for this — the lazy check on page load IS the
        // feature, so it cannot simply be dropped.
        //
        // Ordered after the load on purpose: sections() is unscoped, so the
        // collection above already contains the unpublished ones, and the
        // check costs nothing. Previously the UPDATE ran on every course view
        // whether or not anything was due, which for most courses is never.
        $course->releaseDueSections();

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

        // Per-user section fold state, persisted across devices. A stored row
        // is the user's own decision and always wins; without one the section
        // answers for itself by date — see Section::startsCollapsedByDefault().
        $foldPreferences = DB::table('user_collapsed_sections')
            ->where('user_id', $user->id)
            ->whereIn('section_id', $course->sections->pluck('id'))
            ->pluck('collapsed', 'section_id');

        $collapsedSectionIds = $course->sections
            ->filter(fn ($section) => $foldPreferences->has($section->id)
                ? (bool) $foldPreferences[$section->id]
                : $section->startsCollapsedByDefault())
            ->pluck('id')
            ->values()
            ->all();

        return view('student.courses.show', [
            'course' => $course,
            'canManage' => $canManage,
            'collapsedSectionIds' => $collapsedSectionIds,
        ]);
    }

    /**
     * Record the fold state of one section for the current user.
     *
     * The client sends the state it just rendered. It has to, because the
     * server's idea and the screen can legitimately differ: Expand all and
     * Collapse all move every section on screen without persisting anything,
     * so a section the server believes is collapsed may well be open in front
     * of the user. Flipping the stored value would then store the opposite of
     * what they just did.
     *
     * Without an explicit value — an older cached page, a hand-made request —
     * fall back to flipping the *effective* state, which is the stored row if
     * there is one and otherwise whatever the date rule decided. Never a blind
     * insert-as-collapsed: against a section the rule had already folded that
     * would leave it shut and the click would read as dead.
     *
     * A row is written either way. The click is the user forming an opinion,
     * and from here on this section ignores the date rule. Deleting instead
     * would hand it straight back, which for a past week means it springs
     * shut again on the next page load.
     *
     * Fire-and-forget from the client. Response tells the caller the new
     * state so they can reconcile if needed.
     */
    public function toggleFold(Request $request, Section $section): JsonResponse
    {
        // Scope the write to sections the caller can actually see. Without
        // this any authenticated user could POST arbitrary section IDs and
        // accumulate rows for courses they have no access to.
        $this->authorize('view', $section);

        $userId = $request->user()->id;

        $stored = DB::table('user_collapsed_sections')
            ->where('user_id', $userId)
            ->where('section_id', $section->id)
            ->value('collapsed');

        if ($request->has('collapsed')) {
            $collapsed = $request->boolean('collapsed');
        } else {
            $collapsed = $stored === null
                ? ! $section->startsCollapsedByDefault()
                : ! (bool) $stored;
        }

        DB::table('user_collapsed_sections')->upsert(
            [[
                'user_id' => $userId,
                'section_id' => $section->id,
                'collapsed' => $collapsed,
                'created_at' => now(),
            ]],
            ['user_id', 'section_id'],
            ['collapsed']
        );

        return response()->json(['collapsed' => $collapsed]);
    }
}
