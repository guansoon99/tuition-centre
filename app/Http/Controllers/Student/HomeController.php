<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\BannerSlide;
use App\Models\Course;
use App\Models\User;
use App\Support\Cache\CacheKeys;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HomeController extends Controller
{
    public function index(Request $request): View
    {
        if (! $request->user()) {
            return $this->publicLanding();
        }

        return $this->dashboard($request->user());
    }

    private function publicLanding(): View
    {
        $slides = Cache::remember('public:banner_slides', 300, fn () =>
            BannerSlide::where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );

        return view('public.home', ['slides' => $slides]);
    }

    private function dashboard(User $user): View
    {
        // All courses, ordered by newest first (created_at DESC). The
        // "Recently accessed" strip above this section handles per-user
        // recency separately.
        $allCourses = Cache::remember(
            CacheKeys::userEnrolled($user->id),
            CacheKeys::TTL_ENROLLED,
            fn () => Course::query()
                ->visibleTo($user)
                ->orderByDesc('created_at')
                ->get()
        );

        // Recently-accessed strip: the top 6 from course_views for this
        // user, restricted to visits in the last 10 days. Courses not
        // opened for longer than that quietly drop off the strip (the
        // row stays in course_views — only the UI hides it).
        $recentCutoff = now()->subDays(10);

        $recentCourses = Cache::remember(
            CacheKeys::userRecent($user->id),
            CacheKeys::TTL_RECENT,
            fn () => Course::query()
                ->select('courses.*')
                ->join('course_views', function ($j) use ($user, $recentCutoff) {
                    $j->on('course_views.course_id', '=', 'courses.id')
                        ->where('course_views.user_id', $user->id)
                        ->where('course_views.accessed_at', '>=', $recentCutoff);
                })
                ->visibleTo($user)
                ->orderByDesc('course_views.accessed_at')
                ->limit(6)
                ->get()
        );

        $notifications = $user->visibleNotifications()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('student.home', [
            'user' => $user,
            'recentCourses' => $recentCourses,
            'allCourses' => $allCourses,
            'notifications' => $notifications,
        ]);
    }
}
