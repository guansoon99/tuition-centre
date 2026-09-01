<?php

namespace Tests\Feature\Teacher;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A student who has not submitted reads the same before and after the due date.
 *
 * The roster used to switch to a red "Missing" badge on a red row once the
 * deadline passed. It now says "Not submitted" either way — the deadline no
 * longer changes how the row looks, only what the student is allowed to do.
 */
class RosterNotSubmittedLabelTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Section $section;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web'])
            ->givePermissionTo('sections.manage');

        $this->course = Course::factory()->create(['is_active' => true]);
        $this->section = Section::factory()->create([
            'course_id' => $this->course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);

        $this->teacher = User::factory()->create(['is_active' => true]);
        $this->teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        // One student, enrolled, who never submits anything.
        $student = User::factory()->create(['is_active' => true, 'name' => 'Idle Student']);
        $student->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    private function rosterFor(?string $dueDate): string
    {
        $assignment = Material::factory()->create([
            'section_id' => $this->section->id,
            'type' => Material::TYPE_ASSIGNMENT,
            'is_published' => true,
            'due_date' => $dueDate,
        ]);

        return $this->actingAs($this->teacher)
            ->get(route('materials.view', $assignment))
            ->assertOk()
            ->getContent();
    }

    public function test_before_the_due_date_it_says_not_submitted(): void
    {
        $html = $this->rosterFor(now()->addWeek()->toDateTimeString());

        $this->assertStringContainsString('Not submitted', $html);
        $this->assertStringNotContainsString('>
                                            Missing', $html);
    }

    /** The case that changed: past due used to read "Missing". */
    public function test_past_the_due_date_it_still_says_not_submitted(): void
    {
        $html = $this->rosterFor(now()->subWeek()->toDateTimeString());

        $this->assertStringContainsString('Not submitted', $html);
        $this->assertStringNotContainsString('Missing', $html);
    }

    /** And the row keeps its ordinary border rather than turning red. */
    public function test_the_row_is_not_highlighted_after_the_deadline(): void
    {
        $html = $this->rosterFor(now()->subWeek()->toDateTimeString());

        $this->assertStringNotContainsString('border-red-200 bg-red-50', $html,
            'An overdue row should look like any other.');
    }

    /** An assignment with no deadline at all behaves the same way. */
    public function test_an_assignment_with_no_due_date_says_not_submitted(): void
    {
        $html = $this->rosterFor(null);

        $this->assertStringContainsString('Not submitted', $html);
        $this->assertStringNotContainsString('Missing', $html);
    }
}
