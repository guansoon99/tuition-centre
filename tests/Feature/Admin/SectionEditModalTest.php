<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The "Edit section" modal on the Materials tab is fetched on open.
 *
 * It used to be rendered inline once per section: on a school-year course
 * that was 25 complete forms hidden in the page on every load, 157 KB of the
 * 790 KB the tab weighed. The page now ships one empty shell, and the form
 * arrives from sections.edit-modal when someone clicks Edit — the same
 * arrangement MaterialEditModalTest covers for materials.
 *
 * Two things have to hold: the fragment must serve the form, and the tab
 * must have stopped carrying it. Either alone is easy to leave half-done.
 */
class SectionEditModalTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Section $section;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web'])
            ->givePermissionTo(['sections.manage', 'courses.view']);

        $this->course = Course::factory()->create(['is_active' => true]);
        $this->section = Section::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Minggu Tiga',
            'is_published' => true,
            'scheduled_at' => null,
        ]);

        $this->teacher = User::factory()->create(['is_active' => true]);
        $this->teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    // ---- The fragment ---------------------------------------------------------

    public function test_the_fragment_renders_the_edit_form(): void
    {
        $this->actingAs($this->teacher)
            ->get(route('sections.edit-modal', $this->section))
            ->assertOk()
            ->assertSee('Edit section')
            ->assertSee('Minggu Tiga')
            ->assertSee(route('sections.update', $this->section))
            ->assertSee(route('sections.destroy', $this->section))
            ->assertSee('name="never_collapses"', false)
            ->assertSee('<select name="is_published"', false);
    }

    /**
     * No layout, and nothing pushed to a head stack that has already been
     * rendered — a @push in here would be discarded without a word.
     */
    public function test_the_fragment_has_no_layout_chrome(): void
    {
        $html = $this->actingAs($this->teacher)
            ->get(route('sections.edit-modal', $this->section))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<!DOCTYPE', $html);
        $this->assertStringNotContainsString('<body', $html);
        $this->assertStringNotContainsString('@push', $html);
    }

    // ---- The tab no longer carries it -------------------------------------

    /**
     * The whole point. If a form for this section is still in the tab, the
     * fragment is duplication rather than a saving.
     */
    public function test_the_materials_tab_no_longer_inlines_the_section_form(): void
    {
        $html = $this->actingAs($this->teacher)
            ->get(route('courses.edit', $this->course).'?tab=materials')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('action="'.route('sections.update', $this->section).'"', $html);
        $this->assertStringNotContainsString('name="never_collapses"', $html);
        $this->assertStringNotContainsString('<select name="is_published"', $html);

        // But the shell that receives the fragment is there, wired to the
        // right endpoint.
        $this->assertStringContainsString('/sections/{id}/edit-modal', $html);
        $this->assertStringContainsString('openSection = '.$this->section->id, $html);
    }

    /** One shell for the page, not one per section. */
    public function test_the_shell_appears_once_however_many_sections(): void
    {
        Section::factory()->count(4)->create([
            'course_id' => $this->course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);

        $html = $this->actingAs($this->teacher)
            ->get(route('courses.edit', $this->course).'?tab=materials')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'x-show="openSection !== null"'));
    }

    // ---- Access -----------------------------------------------------------

    public function test_students_cannot_fetch_the_fragment(): void
    {
        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $this->actingAs($student)
            ->get(route('sections.edit-modal', $this->section))
            ->assertForbidden();
    }

    public function test_staff_not_on_the_course_are_refused(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('teacher');

        $this->actingAs($outsider)
            ->get(route('sections.edit-modal', $this->section))
            ->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('sections.edit-modal', $this->section))
            ->assertRedirect('/login');
    }
}
