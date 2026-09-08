<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\Section;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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

    public function create(User $user, Course $course): Response|bool
    {
        return $this->manages($user, $course);
    }

    public function update(User $user, Section $section): Response|bool
    {
        return $this->manages($user, $section->course);
    }

    public function delete(User $user, Section $section): Response|bool
    {
        return $this->manages($user, $section->course);
    }

    /**
     * Two checks, and the difference matters to the person refused: the
     * permission says what kind of work they may do, the teacher assignment
     * says on which courses. Missing the permission is a plain 403; holding
     * it but not teaching this course gets told so, because that is the one
     * an admin fixes on the Teachers tab, not on the roles screen.
     */
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
