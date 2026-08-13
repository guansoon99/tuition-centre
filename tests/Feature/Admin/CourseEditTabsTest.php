<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Render coverage for the four tabs of the course edit screen.
 *
 * The page was one 1,100-line Blade file and each tab body now lives in its
 * own partial. Only the students tab had any coverage at the time, so these
 * exist to make that split verifiable — and afterwards to catch a partial
 * that stops receiving a variable it needs.
 */
class CourseEditTabsTest extends TestCase
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

        $this->course = Course::factory()->create([
            'code' => 'PHY-101', 'name' => 'Physics One', 'is_active' => true,
        ]);
    }

    private function seedContent(): void
    {
        $teacher = User::factory()->create(['name' => 'Tutor Tan']);
        $teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $student = User::factory()->create(['name' => 'Pupil Lim', 'username' => 'pupil_lim']);
        $student->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $section = Section::factory()->create([
            'course_id' => $this->course->id, 'title' => 'Kinematics Week', 'is_published' => true,
        ]);
        Material::factory()->create([
            'section_id' => $section->id, 'title' => 'Motion Handout',
            'type' => Material::TYPE_PDF, 'is_published' => true,
        ]);
    }

    public function test_details_tab_renders(): void
    {
        $this->actingAs($this->admin)
            ->get("/courses/{$this->course->slug}/edit?tab=details")
            ->assertOk()
            ->assertSee('Physics One')
            ->assertSee('PHY-101');
    }

    public function test_teachers_tab_renders_with_assigned_staff(): void
    {
        $this->seedContent();

        $this->actingAs($this->admin)
            ->get("/courses/{$this->course->slug}/edit?tab=teachers")
            ->assertOk()
            ->assertSee('Tutor Tan');
    }

    public function test_students_tab_renders_with_enrollments(): void
    {
        $this->seedContent();

        $this->actingAs($this->admin)
            ->get("/courses/{$this->course->slug}/edit?tab=students")
            ->assertOk()
            ->assertSee('pupil_lim');
    }

    public function test_materials_tab_renders_sections_and_materials(): void
    {
        $this->seedContent();

        $this->actingAs($this->admin)
            ->get("/courses/{$this->course->slug}/edit?tab=materials")
            ->assertOk()
            ->assertSee('Kinematics Week')
            ->assertSee('Motion Handout');
    }

    /**
     * The pushed style/script blocks moved into partials too — if either
     * include broke, the page would render without its editor assets.
     */
    public function test_page_still_pushes_its_styles_and_scripts(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/courses/{$this->course->slug}/edit")
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('quill', $html, 'Quill assets missing.');
        $this->assertStringContainsString('flatpickr', $html, 'Flatpickr assets missing.');
        $this->assertStringContainsString('tom-select', $html, 'Tom Select assets missing.');
    }

    public function test_all_tabs_render_on_a_course_with_no_content(): void
    {
        foreach (['details', 'teachers', 'students', 'materials'] as $tab) {
            $this->actingAs($this->admin)
                ->get("/courses/{$this->course->slug}/edit?tab={$tab}")
                ->assertOk();
        }
    }
}
