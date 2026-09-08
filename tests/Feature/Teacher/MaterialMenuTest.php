<?php

namespace Tests\Feature\Teacher;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The vertical three-dot menu on each resource of the Materials tab: Edit,
 * Move right (and Move left once indented), Hide (Show when hidden), Delete.
 *
 * There is ONE menu per page, opened next to whichever row button was
 * clicked; the row carries only a button with the resource's id, indent and
 * published flag. That is what keeps page weight flat as materials grow
 * (MaterialEditModalTest guards the number). "Move right" is an indent,
 * shown on the student course page as well.
 */
class MaterialMenuTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->course = Course::factory()->create(['is_active' => true]);
        $this->section = Section::factory()->create([
            'course_id' => $this->course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
    }

    private function material(array $attributes = []): Material
    {
        return Material::factory()->create(array_merge([
            'section_id' => $this->section->id,
            'title' => 'Motion Handout',
            'type' => Material::TYPE_PDF,
            'is_published' => true,
        ], $attributes));
    }

    private function tab(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('courses.edit', ['course' => $this->course, 'tab' => 'materials']))
            ->assertOk()
            ->getContent();
    }

    /** What the row's button hands the shared menu. */
    private function rowButton(Material $material, int $indent, bool $published): string
    {
        return sprintf('id: %d, indent: %d, published: %s', $material->id, $indent, $published ? 'true' : 'false');
    }

    public function test_each_row_has_a_menu_button_and_the_menu_exists_once(): void
    {
        $a = $this->material();
        $b = $this->material(['title' => 'Second']);

        $html = $this->tab();

        // One button per row: the pencil, which opens the menu.
        $this->assertSame(2, substr_count($html, 'title="Edit material"'));
        $this->assertStringNotContainsString('Resource actions', $html);
        $this->assertStringContainsString($this->rowButton($a, 0, true), $html);
        $this->assertStringContainsString($this->rowButton($b, 0, true), $html);

        // The menu, its forms and its URL templates: once.
        $this->assertSame(1, substr_count($html, 'x-data="materialMenu()"'));
        foreach (['move-right', 'move-left', 'hide', 'unhide', 'destroy'] as $action) {
            // The whole attribute: the destroy URL is a prefix of the others.
            $this->assertSame(1, substr_count($html, 'data-url-'.$action.'="'.route('materials.'.$action, ['material' => '__ID__']).'"'), $action);
        }
        $this->assertStringContainsString('openMaterial = item.id', $html);
        $this->assertStringNotContainsString('style="padding-left:', $html, 'Nothing is indented yet.');
    }

    public function test_a_hidden_resource_shows_a_badge_and_reports_itself_hidden(): void
    {
        $material = $this->material(['is_published' => false]);

        $html = $this->tab();

        $this->assertStringContainsString('>Hidden<', $html);
        $this->assertStringContainsString($this->rowButton($material, 0, false), $html);
    }

    public function test_hide_and_show_flip_the_published_flag(): void
    {
        $material = $this->material();

        $this->actingAs($this->admin)
            ->post(route('materials.hide', $material))
            ->assertRedirect(route('courses.edit', [$this->course, 'tab' => 'materials']));
        $this->assertFalse($material->fresh()->is_published);

        $this->actingAs($this->admin)
            ->post(route('materials.unhide', $material))
            ->assertRedirect();
        $this->assertTrue($material->fresh()->is_published);
    }

    public function test_move_right_indents_up_to_the_limit(): void
    {
        $material = $this->material();

        foreach (range(1, Material::MAX_INDENT + 1) as $i) {
            $this->actingAs($this->admin)->post(route('materials.move-right', $material))->assertRedirect();
        }

        $this->assertSame(Material::MAX_INDENT, $material->fresh()->indent, 'Stops at the limit rather than growing forever.');

        $html = $this->tab();
        $this->assertStringContainsString('style="padding-left: '.(Material::MAX_INDENT * 1.5).'rem"', $html);
        $this->assertStringContainsString($this->rowButton($material, Material::MAX_INDENT, true), $html);
    }

    public function test_move_left_goes_back_and_stops_at_zero(): void
    {
        $material = $this->material(['indent' => 1]);

        $this->actingAs($this->admin)->post(route('materials.move-left', $material))->assertRedirect();
        $this->assertSame(0, $material->fresh()->indent);

        $this->actingAs($this->admin)->post(route('materials.move-left', $material))->assertRedirect();
        $this->assertSame(0, $material->fresh()->indent, 'Nothing further left than the margin.');
    }

    public function test_one_level_only_so_the_menu_offers_one_direction_at_a_time(): void
    {
        $this->assertSame(1, Material::MAX_INDENT);

        $material = $this->material();
        $this->actingAs($this->admin)->post(route('materials.move-right', $material))->assertRedirect();

        // After one Move right the row reports indent 1, which the menu
        // reads as: hide Move right, show Move left.
        $this->assertStringContainsString($this->rowButton($material, 1, true), $this->tab());
    }

    public function test_the_student_course_page_shows_the_indent_too(): void
    {
        $this->material(['indent' => 1]);
        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $this->actingAs($student)
            ->get(route('courses.show', $this->course))
            ->assertOk()
            ->assertSee('Motion Handout')
            ->assertSee('style="padding-left: 1.5rem"', false);
    }

    public function test_the_menu_actions_need_the_manage_permission(): void
    {
        $material = $this->material();
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($student)->post(route('materials.hide', $material))->assertForbidden();
        $this->actingAs($student)->post(route('materials.move-right', $material))->assertForbidden();

        $this->assertTrue($material->fresh()->is_published);
        $this->assertSame(0, $material->fresh()->indent);
    }

    public function test_from_outside_the_course_the_refusal_says_why(): void
    {
        $material = $this->material();
        Permission::firstOrCreate(['name' => 'sections.manage', 'guard_name' => 'web']);
        Role::findByName('teacher')->givePermissionTo('sections.manage');
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('teacher');

        $this->actingAs($outsider)
            ->post(route('materials.move-right', $material))
            ->assertForbidden()
            ->assertSee(Course::NOT_A_TEACHER_MESSAGE);
    }
}
