<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\Section;
use App\Models\User;

class SectionPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasRole('admin') ? true : null;
    }

    public function view(User $user, Section $section): bool
    {
        $course = $section->course;

        if ($user->teaches($course)) {
            return true;
        }

        return $section->isVisibleToStudents() && $user->isEnrolledIn($course);
    }

    public function create(User $user, Course $course): bool
    {
        return $user->can('sections.manage') && $user->teaches($course);
    }

    public function update(User $user, Section $section): bool
    {
        return $user->can('sections.manage') && $user->teaches($section->course);
    }

    public function delete(User $user, Section $section): bool
    {
        return $user->can('sections.manage') && $user->teaches($section->course);
    }
}
