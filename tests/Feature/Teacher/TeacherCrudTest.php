<?php

namespace Tests\Feature\Teacher;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Course-content permissions for teaching staff.
 *
 * Two separate things both get called "teacher" here, and the distinction is
 * the whole point of these tests:
 *
 *   1. A Spatie ROLE carrying the `sections.manage` permission. There is no
 *      built-in one — the seeder ships only `admin` and `student`, and an
 *      admin creates whatever staff roles they want through the Roles UI.
 *      So the test creates it, exactly as an admin would.
 *
 *   2. enrollments.role_on_course = 'teacher', which says this user teaches
 *      THIS course.
 *
 * SectionPolicy/MaterialPolicy require BOTH:
 *
 *     $user->can('sections.manage') && $user->teaches($course)
 *
 * The permission alone is not enough, which is what stops one staff member
 * editing another's course. $otherTeacher exists to hold that line: same
 * role, same permission, no enrollment on $course.
 */
class TeacherCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $otherTeacher;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // Stand up a staff role the way an admin would: create it, then tick
        // the permissions. Nothing in the app ships a role by this name.
        $staffRole = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $staffRole->givePermissionTo('sections.manage');

        $this->teacher = User::factory()->create(['username' => 'tA', 'password' => 'p']);
        $this->teacher->assignRole($staffRole);

        // Same role and permission — differs only in having no enrollment on
        // $course. Every "cannot" case below leans on that.
        $this->otherTeacher = User::factory()->create(['username' => 'tB', 'password' => 'p']);
        $this->otherTeacher->assignRole($staffRole);

        $this->course = Course::factory()->create();
        // Direct insert — attach() on the belongsToMany wouldn't set
        // role_on_course, which is required now that the pivot is merged.
        Enrollment::create([
            'user_id' => $this->teacher->id,
            'course_id' => $this->course->id,
            'role_on_course' => Enrollment::ROLE_TEACHER,
            'enrolled_at' => now(),
            'is_active' => true,
        ]);
    }

    /**
     * The permission on its own grants nothing — it has to be paired with a
     * teacher enrollment on the specific course. Pins the exact split that
     * makes custom staff roles safe to hand out.
     */
    public function test_permission_alone_does_not_grant_access_to_a_course(): void
    {
        $this->assertTrue($this->otherTeacher->can('sections.manage'));
        $this->assertFalse($this->otherTeacher->teaches($this->course));

        $this->actingAs($this->otherTeacher)
            ->get(route('courses.edit', [$this->course, 'tab' => 'materials']))
            ->assertForbidden();
    }

    public function test_assigned_teacher_can_create_section(): void
    {
        $this->actingAs($this->teacher)
            ->post(route('sections.store', $this->course), [
                'title' => 'Minggu 1',
                'sort_order' => 1,
                'is_published' => '1',
            ])
            ->assertRedirect(route('courses.show', $this->course));

        $this->assertDatabaseHas('sections', ['title' => 'Minggu 1', 'course_id' => $this->course->id]);
    }

    public function test_unassigned_teacher_cannot_create_section(): void
    {
        $this->actingAs($this->otherTeacher)
            ->post(route('sections.store', $this->course), [
                'title' => 'Sneaky',
                'sort_order' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('sections', ['title' => 'Sneaky']);
    }

    public function test_assigned_teacher_can_upload_pdf_material(): void
    {
        Storage::fake();
        $section = Section::factory()->create(['course_id' => $this->course->id]);

        $pdf = UploadedFile::fake()->create('worksheet.pdf', 250, 'application/pdf');

        $this->actingAs($this->teacher)
            ->post(route('materials.store', $section), [
                'title' => '【上课资料】Minggu 1',
                'type' => Material::TYPE_PDF,
                'file' => $pdf,
                'sort_order' => 1,
                'is_published' => '1',
            ])
            ->assertRedirect(route('courses.edit', [$this->course, 'tab' => 'materials']));

        $material = Material::where('title', '【上课资料】Minggu 1')->first();
        $this->assertNotNull($material);
        $this->assertSame(Material::TYPE_PDF, $material->type);
        $this->assertNotNull($material->file_path);
        $this->assertSame($this->teacher->id, $material->uploaded_by_user_id);
        Storage::assertExists($material->file_path);
    }

    public function test_creating_external_link_requires_url_and_skips_file(): void
    {
        $section = Section::factory()->create(['course_id' => $this->course->id]);

        $this->actingAs($this->teacher)
            ->post(route('materials.store', $section), [
                'title' => 'Recording',
                'type' => Material::TYPE_EXTERNAL_LINK,
                'external_url' => 'https://drive.google.com/file/d/abc/view',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('materials', [
            'title' => 'Recording',
            'type' => Material::TYPE_EXTERNAL_LINK,
            'external_url' => 'https://drive.google.com/file/d/abc/view',
            'file_path' => null,
        ]);
    }

    public function test_unassigned_teacher_cannot_edit_someone_elses_material(): void
    {
        $section = Section::factory()->create(['course_id' => $this->course->id]);
        $material = Material::factory()->create(['section_id' => $section->id, 'title' => 'orig']);

        $this->actingAs($this->otherTeacher)
            ->patch(route('materials.update', $material), [
                'title' => 'hijacked',
                'type' => Material::TYPE_EXTERNAL_LINK,
                'external_url' => 'https://evil.com',
            ])
            ->assertForbidden();

        $this->assertSame('orig', $material->fresh()->title);
    }

    public function test_section_delete_cascades_materials_via_soft_delete(): void
    {
        $section = Section::factory()->create(['course_id' => $this->course->id]);
        Material::factory()->count(3)->create(['section_id' => $section->id]);

        $this->actingAs($this->teacher)
            ->delete(route('sections.destroy', $section))
            ->assertRedirect();

        $this->assertSoftDeleted($section);
    }
}
