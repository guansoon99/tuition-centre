<?php

namespace Tests\Feature\Admin;

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
 * The edit-material modal is fetched when opened rather than rendered inline
 * for every material. These cover the new endpoint's authorisation and pin
 * the page-weight win so it can't silently regress.
 */
class MaterialEditModalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Seeds the permission catalog as well as the admin/student roles —
        // the staff-role test below needs `sections.manage` to exist.
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeMaterial(Course $course, string $type = Material::TYPE_TEXT): Material
    {
        $section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);

        return Material::factory()->create([
            'section_id' => $section->id,
            'title' => 'Editable Material',
            'type' => $type,
            'body' => '<p>hello</p>',
            'is_published' => true,
        ]);
    }

    public function test_modal_fragment_renders_the_edit_form(): void
    {
        $material = $this->makeMaterial(Course::factory()->create());

        $this->actingAs($this->admin)
            ->get(route('materials.edit-modal', $material))
            ->assertOk()
            ->assertSee('Edit material')
            ->assertSee('Editable Material')
            ->assertSee(route('materials.update', $material));
    }

    /** It's a fragment for injection — a full HTML document would nest a page inside the modal. */
    public function test_fragment_has_no_layout_chrome(): void
    {
        $material = $this->makeMaterial(Course::factory()->create());

        $html = $this->actingAs($this->admin)
            ->get(route('materials.edit-modal', $material))
            ->getContent();

        $this->assertStringNotContainsString('<!DOCTYPE', $html);
        $this->assertStringNotContainsString('<body', $html);
    }

    public function test_students_cannot_fetch_the_edit_fragment(): void
    {
        $material = $this->makeMaterial(Course::factory()->create());

        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');

        $this->actingAs($student)
            ->get(route('materials.edit-modal', $material))
            ->assertForbidden();
    }

    /**
     * Same rule as the rest of the material routes: sections.manage on its
     * own is not enough without teaching the course.
     */
    public function test_staff_without_an_enrollment_on_the_course_are_refused(): void
    {
        $staffRole = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $staffRole->givePermissionTo('sections.manage');

        $theirCourse = Course::factory()->create();
        $otherCourse = Course::factory()->create();
        $material = $this->makeMaterial($theirCourse);

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole($staffRole);
        Enrollment::create([
            'course_id' => $otherCourse->id, 'user_id' => $outsider->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $this->actingAs($outsider)
            ->get(route('materials.edit-modal', $material))
            ->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $material = $this->makeMaterial(Course::factory()->create());

        $this->get(route('materials.edit-modal', $material))->assertRedirect('/login');
    }

    /**
     * The modals are wired through Alpine, which these tests can't exercise.
     * At minimum, assert the page carries exactly one of each shell and that
     * they're pointed at the right endpoints — that catches a typo'd URL or a
     * shell accidentally landing back inside the material loop.
     */
    public function test_page_renders_one_shared_shell_per_modal(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        Material::factory()->count(6)->create([
            'section_id' => $section->id, 'type' => Material::TYPE_TEXT, 'is_published' => true,
        ]);

        $html = $this->actingAs($this->admin)
            ->get("/courses/{$course->slug}/edit?tab=materials")
            ->assertOk()
            ->getContent();

        // Two shells total — edit + add — regardless of how many materials
        // and sections the course has.
        $this->assertSame(2, substr_count($html, 'materialEditModal()'), 'Expected exactly two modal shells.');

        // Each pointed at its own endpoint. The edit URL appears twice (the
        // $watch and the retry button), the create URL likewise.
        $this->assertSame(1, substr_count($html, '/materials/{id}/edit-modal'));
        $this->assertSame(2, substr_count($html, '/sections/{id}/materials/create-modal'));

        // And the per-material modal markup is genuinely gone.
        $this->assertStringNotContainsString('Edit modal for this material', $html);
        $this->assertStringNotContainsString('Add-resource modal for this section', $html);
    }

    /**
     * The point of the whole change: page weight must stay flat as materials
     * are added, instead of growing by a full modal each time.
     */
    public function test_page_weight_does_not_scale_with_material_count(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);

        $url = "/courses/{$course->slug}/edit?tab=materials";

        Material::factory()->count(5)->create([
            'section_id' => $section->id, 'type' => Material::TYPE_TEXT,
            'body' => str_repeat('content ', 50), 'is_published' => true,
        ]);
        $small = strlen($this->actingAs($this->admin)->get($url)->getContent());

        Material::factory()->count(55)->create([
            'section_id' => $section->id, 'type' => Material::TYPE_TEXT,
            'body' => str_repeat('content ', 50), 'is_published' => true,
        ]);
        $large = strlen($this->actingAs($this->admin)->get($url)->getContent());

        $growthPerMaterial = ($large - $small) / 55;

        // Each row is an icon, a title and an edit button — low single-digit KB.
        // A re-inlined modal would put this back into the tens of KB.
        $this->assertLessThan(
            4096,
            $growthPerMaterial,
            sprintf('Page grew %d bytes per material — is the modal being rendered inline again?', $growthPerMaterial)
        );
    }
}
