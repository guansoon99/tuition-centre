<?php

namespace App\Policies;

use App\Models\Material;
use App\Models\Section;
use App\Models\Course;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MaterialPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasRole('admin') ? true : null;
    }

    public function view(User $user, Material $material): bool
    {
        $course = $material->section->course;

        if ($user->teaches($course)) {
            return true;
        }

        if (! $material->is_published || ! $material->section->isVisibleToStudents()) {
            return false;
        }

        return $user->isEnrolledIn($course);
    }

    public function download(User $user, Material $material): bool
    {
        return $this->view($user, $material);
    }

    public function create(User $user, Section $section): Response|bool
    {
        return $this->manages($user, $section->course);
    }

    public function update(User $user, Material $material): Response|bool
    {
        return $this->manages($user, $material->section->course);
    }

    public function delete(User $user, Material $material): Response|bool
    {
        return $this->manages($user, $material->section->course);
    }

    /** Same split as SectionPolicy::manages(), for the same reason. */
    private function manages(User $user, Course $course): Response|bool
    {
        if (! $user->can('sections.manage')) {
            return false;
        }

        return $user->teaches($course)
            ? true
            : Response::deny(Course::NOT_A_TEACHER_MESSAGE);
    }
}
