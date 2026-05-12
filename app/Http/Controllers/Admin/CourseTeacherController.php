<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use App\Support\Cache\CacheKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

        $course->teachers()->syncWithoutDetaching([
            $teacher->id => [
                'assigned_at' => $data['assigned_at'] ?? now(),
                'ends_at' => $data['ends_at'] ?? null,
            ],
        ]);

        Cache::forget(CacheKeys::userAssigned($teacher->id));

        return back()->with('status', "Assigned {$teacher->name} to {$course->code}.");
    }

    public function destroy(Course $course, User $user): RedirectResponse
    {
        $course->teachers()->detach($user->id);

        Cache::forget(CacheKeys::userAssigned($user->id));

        return back()->with('status', "Removed {$user->name} from {$course->code}.");
    }
}
