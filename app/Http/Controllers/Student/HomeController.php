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
        $allCourses = Cache::remember(
            CacheKeys::userEnrolled($user->id),
            CacheKeys::TTL_ENROLLED,
            fn () => Course::query()
                ->visibleTo($user)
                ->orderBy('name')
                ->get()
        );

        $recentCourses = Cache::remember(
            CacheKeys::userRecent($user->id),
            CacheKeys::TTL_RECENT,
            fn () => Course::query()
                ->select('courses.*')
                ->join('enrollments', function ($j) use ($user) {
                    $j->on('enrollments.course_id', '=', 'courses.id')
                        ->where('enrollments.user_id', $user->id)
                        ->whereNotNull('enrollments.last_accessed_at');
                })
                ->visibleTo($user)
                ->orderByDesc('enrollments.last_accessed_at')
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
