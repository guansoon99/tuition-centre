<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function store(Request $request, Course $course): RedirectResponse
    {
        $this->authorizeCourseAccess($request->user(), $course);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'enrolled_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:enrolled_at'],
        ]);

        $student = User::findOrFail($data['user_id']);
        abort_unless($student->hasRole('student'), 422, 'User is not a student.');

        Enrollment::firstOrCreate(
            ['user_id' => $student->id, 'course_id' => $course->id],
            [
                'enrolled_at' => $data['enrolled_at'] ?? now(),
                'expires_at' => $data['expires_at'] ?? null,
                'is_active' => true,
            ]
        );

        return back()->with('status', "Enrolled {$student->username}.");
    }

    public function update(Request $request, Course $course, Enrollment $enrollment): RedirectResponse
    {
        $this->authorizeCourseAccess($request->user(), $course);
        abort_unless($enrollment->course_id === $course->id, 404);

        $data = $request->validate([
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $enrollment->update([
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Enrollment updated.');
    }

    public function destroy(Request $request, Course $course, Enrollment $enrollment): RedirectResponse
    {
        $this->authorizeCourseAccess($request->user(), $course);
        abort_unless($enrollment->course_id === $course->id, 404);

        $enrollment->delete();

        return back()->with('status', 'Enrollment removed.');
    }

    /**
     * Teachers can only manage enrollments for active courses they're
     * assigned to. Admin bypasses.
     */
    private function authorizeCourseAccess(User $user, Course $course): void
    {
        if ($user->hasRole('admin')) {
            return;
        }
        abort_unless($user->teaches($course) && $course->is_active, 403);
    }
}
