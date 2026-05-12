<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasRole('admin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['teacher', 'student']);
    }

    public function view(User $user, Course $course): bool
    {
        if (! $course->is_active) {
            return false;
        }

        // Course staff (anyone in the course_teacher pivot) — teachers,
        // custom managers, etc.
        if ($user->teaches($course)) {
            return true;
        }

        // Students see enrolled courses.
        return $user->isEnrolledIn($course);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Course $course): bool
    {
        return false;
    }

    public function delete(User $user, Course $course): bool
    {
        return false;
    }

    public function assignTeachers(User $user, Course $course): bool
    {
        return false;
    }

    public function enrollStudents(User $user, Course $course): bool
    {
        return false;
    }

    public function manageContent(User $user, Course $course): bool
    {
        return $user->can('sections.manage') && $user->teaches($course);
    }
}
