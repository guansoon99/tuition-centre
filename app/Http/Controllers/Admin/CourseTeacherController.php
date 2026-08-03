<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\Cache\CacheKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Teacher assignments live in the shared `enrollments` table (role_on_course
 * = 'teacher') since the course_teacher pivot was merged in. This controller
 * still speaks the teacher-management vocabulary — assign / remove — it just
 * writes Enrollment rows underneath.
 */
class CourseTeacherController extends Controller
{
    public function store(Request $request, Course $course): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'assigned_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:assigned_at'],
        ]);

        $teacher = User::findOrFail($data['user_id']);
        abort_if($teacher->hasRole('student'), 422, 'Cannot assign a student as course staff.');

        // Upsert the teacher membership. A user can also hold a separate
        // student row for the same course — those two coexist safely thanks
        // to the (user_id, course_id, role_on_course) unique constraint.
        Enrollment::updateOrCreate(
            [
                'user_id' => $teacher->id,
                'course_id' => $course->id,
                'role_on_course' => Enrollment::ROLE_TEACHER,
            ],
            [
                'enrolled_at' => $data['assigned_at'] ?? now(),
                'expires_at' => $data['ends_at'] ?? null,
                'is_active' => true,
            ]
        );

        Cache::forget(CacheKeys::userAssigned($teacher->id));

        return back()->with('status', "Assigned {$teacher->name} to {$course->code}.");
    }

    public function destroy(Course $course, User $user): RedirectResponse
    {
        // Only remove the teacher row — leave any student enrollment alone.
        Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('role_on_course', Enrollment::ROLE_TEACHER)
            ->delete();

        Cache::forget(CacheKeys::userAssigned($user->id));

        return back()->with('status', "Removed {$user->name} from {$course->code}.");
    }
}
