<?php

namespace Tests\Feature\Teacher;

use App\Models\Course;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The three-dot menu on each section of the Materials tab: Edit Section,
 * Hide Section (Show Section when hidden), Delete Section. Hide and Show
 * are the Status select of the edit form without the form, and stamp
 * published_at by the same rule.
 */
class SectionMenuTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->course = Course::factory()->create(['is_active' => true]);
    }

    private function section(array $attributes = []): Section
    {
        return Section::factory()->create(array_merge([
            'course_id' => $this->course->id,
            'title' => 'Kinematics',
            'is_published' => true,
            'scheduled_at' => null,
            'published_at' => now()->subDays(3),
        ], $attributes));
    }

    private function materialsTab()
    {
        return $this->actingAs($this->admin)
            ->get(route('courses.edit', ['course' => $this->course, 'tab' => 'materials']));
    }

    public function test_the_menu_offers_edit_hide_and_delete_for_a_published_section(): void
    {
        $section = $this->section();

        $html = $this->materialsTab()->assertOk()->getContent();

        $this->assertStringContainsString('Edit Section', $html);
        $this->assertStringContainsString('Hide Section', $html);
        $this->assertStringContainsString('Delete Section', $html);
        $this->assertStringNotContainsString('Show Section', $html);
        // Edit still opens the shared modal, which the modal test relies on.
        $this->assertStringContainsString('openSection = '.$section->id, $html);
        $this->assertStringContainsString(route('sections.hide', $section), $html);
        $this->assertStringContainsString(route('sections.destroy', $section), $html);
    }

    public function test_a_hidden_section_offers_show_instead_of_hide(): void
    {
        $section = $this->section(['is_published' => false, 'published_at' => null]);

        $html = $this->materialsTab()->assertOk()->getContent();

        $this->assertStringContainsString('Show Section', $html);
        $this->assertStringNotContainsString('Hide Section', $html);
        $this->assertStringContainsString(route('sections.unhide', $section), $html);
    }

    public function test_hide_unpublishes_and_clears_published_at(): void
    {
        $section = $this->section();

        $this->actingAs($this->admin)
            ->post(route('sections.hide', $section))
            ->assertRedirect(route('courses.edit', [$this->course, 'tab' => 'materials']));

        $section->refresh();
        $this->assertFalse($section->is_published);
        $this->assertNull($section->published_at, 'Unpublished means no published_at, as the edit form does it.');
        $this->assertFalse($section->isVisibleToStudents());
        $this->assertSame('Kinematics', $section->title, 'Nothing else about the section changes.');
    }

    public function test_hide_also_clears_a_past_available_from_date(): void
    {
        // The date gate wins on its own: a section with a past "Available
        // from" is visible whatever is_published says. Hidden has to mean
        // hidden, so Hide clears the date as well.
        $section = $this->section(['scheduled_at' => now()->subDays(2)]);
        $this->assertTrue($section->isVisibleToStudents());

        $this->actingAs($this->admin)
            ->post(route('sections.hide', $section))
            ->assertRedirect();

        $section->refresh();
        $this->assertNull($section->scheduled_at);
        $this->assertFalse($section->is_published);
        $this->assertFalse($section->isVisibleToStudents(), 'A hidden section must not be visible, date or no date.');
    }

    public function test_show_publishes_an_undated_section_from_now(): void
    {
        $this->travelTo(now()->startOfMinute());
        $section = $this->section(['is_published' => false, 'published_at' => null]);

        $this->actingAs($this->admin)
            ->post(route('sections.unhide', $section))
            ->assertRedirect();

        $section->refresh();
        $this->assertTrue($section->is_published);
        $this->assertTrue($section->published_at->equalTo(now()), 'A section with no date becomes visible now.');
    }

    public function test_show_on_a_dated_section_keeps_the_date_as_the_visibility_moment(): void
    {
        $date = now()->addDays(4)->startOfMinute();
        $section = $this->section(['is_published' => false, 'published_at' => null, 'scheduled_at' => $date]);

        $this->actingAs($this->admin)
            ->post(route('sections.unhide', $section))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'will be visible from'));

        $section->refresh();
        $this->assertTrue($section->is_published);
        $this->assertTrue($section->published_at->equalTo($date));
        $this->assertFalse($section->isVisibleToStudents(), 'Still gated by its future date, exactly as after the form.');
    }

    public function test_hiding_needs_the_manage_permission(): void
    {
        $section = $this->section();
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($student)
            ->post(route('sections.hide', $section))
            ->assertForbidden();

        $this->assertTrue($section->fresh()->is_published);
    }

    public function test_hiding_from_outside_the_course_says_why(): void
    {
        $section = $this->section();
        Permission::firstOrCreate(['name' => 'sections.manage', 'guard_name' => 'web']);
        Role::findByName('teacher')->givePermissionTo('sections.manage');
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('teacher');

        $this->actingAs($outsider)
            ->post(route('sections.hide', $section))
            ->assertForbidden()
            ->assertSee(Course::NOT_A_TEACHER_MESSAGE);
    }
}
