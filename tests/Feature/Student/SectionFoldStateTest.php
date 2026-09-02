<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section fold state is per-user and persists server-side so it follows the
 * student across devices. The toggle endpoint is a write, so it has to be
 * scoped to sections the caller can actually see.
 */
class SectionFoldStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function enrolledStudent(Course $course): User
    {
        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');

        Enrollment::create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT,
            'is_active' => true,
            'enrolled_at' => now(),
        ]);

        return $student;
    }

    private function publishedSection(Course $course): Section
    {
        return Section::factory()->create([
            'course_id' => $course->id,
            'is_published' => true,
            'scheduled_at' => null,
        ]);
    }

    public function test_enrolled_student_can_collapse_and_reopen_a_section(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $section = $this->publishedSection($course);
        $student = $this->enrolledStudent($course);

        // Collapse.
        $this->actingAs($student)
            ->post("/sections/{$section->id}/toggle-fold")
            ->assertOk()
            ->assertJson(['collapsed' => true]);

        $this->assertDatabaseHas('user_collapsed_sections', [
            'user_id' => $student->id,
            'section_id' => $section->id,
            'collapsed' => true,
        ]);

        // Toggling again reopens it. The row stays, now recording an explicit
        // open — deleting it would return the section to the date rule, and a
        // section from a past week would fold itself shut again on the next
        // page load. See Section::startsCollapsedByDefault().
        $this->actingAs($student)
            ->post("/sections/{$section->id}/toggle-fold")
            ->assertOk()
            ->assertJson(['collapsed' => false]);

        $this->assertDatabaseHas('user_collapsed_sections', [
            'user_id' => $student->id,
            'section_id' => $section->id,
            'collapsed' => false,
        ]);
    }

    /**
     * The bug this endpoint shipped with: no authorization at all, so any
     * logged-in user could write rows for sections in courses they have no
     * access to.
     */
    public function test_outsider_cannot_toggle_a_section_they_cannot_see(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $section = $this->publishedSection($course);

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('student'); // enrolled in nothing

        $this->actingAs($outsider)
            ->post("/sections/{$section->id}/toggle-fold")
            ->assertForbidden();

        $this->assertDatabaseMissing('user_collapsed_sections', [
            'user_id' => $outsider->id,
            'section_id' => $section->id,
        ]);
    }

    /**
     * A student enrolled in course A must not be able to fold sections of
     * course B — the enrollment has to be for the section's own course.
     */
    public function test_student_cannot_toggle_a_section_of_another_course(): void
    {
        $mine = Course::factory()->create(['is_active' => true]);
        $theirs = Course::factory()->create(['is_active' => true]);
        $otherSection = $this->publishedSection($theirs);

        $student = $this->enrolledStudent($mine);

        $this->actingAs($student)
            ->post("/sections/{$otherSection->id}/toggle-fold")
            ->assertForbidden();

        $this->assertDatabaseMissing('user_collapsed_sections', [
            'user_id' => $student->id,
            'section_id' => $otherSection->id,
        ]);
    }

    public function test_unpublished_section_cannot_be_toggled_by_a_student(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $draft = Section::factory()->create([
            'course_id' => $course->id,
            'is_published' => false,
            'scheduled_at' => null,
        ]);
        $student = $this->enrolledStudent($course);

        $this->actingAs($student)
            ->post("/sections/{$draft->id}/toggle-fold")
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $section = $this->publishedSection($course);

        $this->post("/sections/{$section->id}/toggle-fold")
            ->assertRedirect('/login');
    }

    /**
     * Fold state is personal — one student's collapse must not affect what
     * another student sees.
     */
    public function test_fold_state_is_per_user(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $section = $this->publishedSection($course);
        $alice = $this->enrolledStudent($course);
        $bob = $this->enrolledStudent($course);

        $this->actingAs($alice)->post("/sections/{$section->id}/toggle-fold");

        $this->assertDatabaseHas('user_collapsed_sections', [
            'user_id' => $alice->id, 'section_id' => $section->id,
        ]);
        $this->assertDatabaseMissing('user_collapsed_sections', [
            'user_id' => $bob->id, 'section_id' => $section->id,
        ]);
    }
}
