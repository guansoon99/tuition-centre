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
 * Managing a course's sections and materials takes two things: the Manage
 * Materials permission, and being a teacher on that course. Someone who has
 * the first but not the second used to get a bare "This action is
 * unauthorized", which reads as a permissions problem. Now they are told
 * which of the two is missing.
 */
class NotATeacherMessageTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Section $section;

    private Material $material;

    /** Teacher role, Manage Materials permission, not on the course. */
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web'])->givePermissionTo('sections.manage');

        $this->course = Course::factory()->create(['is_active' => true]);
        $this->section = Section::factory()->create([
            'course_id' => $this->course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        $this->material = Material::factory()->create([
            'section_id' => $this->section->id, 'type' => Material::TYPE_PDF,
        ]);

        $this->outsider = User::factory()->create(['is_active' => true]);
        $this->outsider->assignRole('teacher');
    }

    private function quickInsert(User $as)
    {
        return $this->actingAs($as)
            ->post(route('sections.quick-insert', $this->course), ['position' => 'first']);
    }

    public function test_adding_a_section_from_outside_the_course_says_why(): void
    {
        $this->quickInsert($this->outsider)
            ->assertForbidden()
            ->assertSee(Course::NOT_A_TEACHER_MESSAGE);
    }

    public function test_editing_a_material_from_outside_the_course_says_why(): void
    {
        $this->actingAs($this->outsider)
            ->patch(route('materials.update', $this->material), ['title' => 'x'])
            ->assertForbidden()
            ->assertSee(Course::NOT_A_TEACHER_MESSAGE);
    }

    public function test_editing_a_section_from_outside_the_course_says_why(): void
    {
        // The section and material forms authorise in their FormRequest, a
        // step before the controller. The message has to survive that too.
        $this->actingAs($this->outsider)
            ->patch(route('sections.update', $this->section), ['title' => 'x'])
            ->assertForbidden()
            ->assertSee(Course::NOT_A_TEACHER_MESSAGE);
    }

    public function test_the_edit_modal_fetch_carries_the_message_as_json(): void
    {
        // The materials tab fetches modal bodies with Accept: application/json
        // first, so a refusal arrives as JSON the modal can show, not as a
        // 403 page it can only call "failed".
        $this->actingAs($this->outsider)
            ->getJson(route('materials.edit-modal', $this->material))
            ->assertForbidden()
            ->assertJsonPath('message', Course::NOT_A_TEACHER_MESSAGE);

        $this->actingAs($this->outsider)
            ->getJson(route('sections.edit-modal', $this->section))
            ->assertForbidden()
            ->assertJsonPath('message', Course::NOT_A_TEACHER_MESSAGE);
    }

    public function test_opening_the_course_edit_page_from_outside_says_why(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('courses.edit', $this->course))
            ->assertForbidden()
            ->assertSee(Course::NOT_A_TEACHER_MESSAGE);
    }

    public function test_without_the_permission_the_refusal_stays_generic(): void
    {
        // Missing permission is the roles screen's problem, not the Teachers
        // tab's — so no teacher message for it.
        $noPermission = User::factory()->create(['is_active' => true]);
        $noPermission->assignRole('student');

        $this->quickInsert($noPermission)
            ->assertForbidden()
            ->assertDontSee(Course::NOT_A_TEACHER_MESSAGE);
    }

    public function test_a_teacher_on_the_course_is_not_refused(): void
    {
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->outsider->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $this->quickInsert($this->outsider)->assertRedirect();
    }

    public function test_an_admin_is_not_refused_anywhere(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->quickInsert($admin)->assertRedirect();
    }
}
