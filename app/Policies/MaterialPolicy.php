<?php

namespace App\Policies;

use App\Models\Material;
use App\Models\Section;
use App\Models\User;

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

    public function create(User $user, Section $section): bool
    {
        return $user->can('sections.manage') && $user->teaches($section->course);
    }

    public function update(User $user, Material $material): bool
    {
        return $user->can('sections.manage') && $user->teaches($material->section->course);
    }

    public function delete(User $user, Material $material): bool
    {
        return $user->can('sections.manage') && $user->teaches($material->section->course);
    }
}
