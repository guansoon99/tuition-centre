<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What the student course page must show, independent of how it's built.
 *
 * These are deliberately written against the rendered page rather than the
 * cache layer: they were added to make removing the courseDetail cache a
 * verifiably behaviour-preserving change, and they stay useful afterwards as
 * a guard on content freshness.
 */
class CoursePageFreshnessTest extends TestCase
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

    public function test_course_page_lists_published_sections_and_materials(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'title' => 'Week One', 'is_published' => true, 'scheduled_at' => null,
        ]);
        Material::factory()->create([
            'section_id' => $section->id, 'title' => 'Algebra Notes',
            'type' => Material::TYPE_PDF, 'is_published' => true,
        ]);

        $student = $this->enrolledStudent($course);

        $this->actingAs($student)->get("/courses/{$course->slug}")
            ->assertOk()
            ->assertSee('Week One')
            ->assertSee('Algebra Notes');
    }

    public function test_a_newly_added_material_shows_up_immediately(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        $student = $this->enrolledStudent($course);

        // Prime the page once — this is what used to populate the cache.
        $this->actingAs($student)->get("/courses/{$course->slug}")->assertOk();

        Material::factory()->create([
            'section_id' => $section->id, 'title' => 'Brand New Handout',
            'type' => Material::TYPE_PDF, 'is_published' => true,
        ]);

        $this->actingAs($student)->get("/courses/{$course->slug}")
            ->assertOk()
            ->assertSee('Brand New Handout');
    }

    public function test_a_renamed_section_shows_its_new_title_immediately(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'title' => 'Old Title', 'is_published' => true, 'scheduled_at' => null,
        ]);
        $student = $this->enrolledStudent($course);

        $this->actingAs($student)->get("/courses/{$course->slug}")->assertSee('Old Title');

        $section->update(['title' => 'Renamed Title']);

        $this->actingAs($student)->get("/courses/{$course->slug}")
            ->assertOk()
            ->assertSee('Renamed Title')
            ->assertDontSee('Old Title');
    }

    public function test_a_deleted_material_disappears_immediately(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        $material = Material::factory()->create([
            'section_id' => $section->id, 'title' => 'Doomed Material',
            'type' => Material::TYPE_PDF, 'is_published' => true,
        ]);
        $student = $this->enrolledStudent($course);

        $this->actingAs($student)->get("/courses/{$course->slug}")->assertSee('Doomed Material');

        $material->delete();

        $this->actingAs($student)->get("/courses/{$course->slug}")
            ->assertOk()
            ->assertDontSee('Doomed Material');
    }

    public function test_unpublished_content_is_hidden_from_students(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $draftSection = Section::factory()->create([
            'course_id' => $course->id, 'title' => 'Secret Draft Section',
            'is_published' => false, 'scheduled_at' => null,
        ]);
        Material::factory()->create([
            'section_id' => $draftSection->id, 'title' => 'Secret Draft Material',
            'type' => Material::TYPE_PDF, 'is_published' => true,
        ]);

        $student = $this->enrolledStudent($course);

        $this->actingAs($student)->get("/courses/{$course->slug}")
            ->assertOk()
            ->assertDontSee('Secret Draft Section')
            ->assertDontSee('Secret Draft Material');
    }

    /**
     * A section with a past scheduled_at auto-publishes on page load. This
     * ran inside the cached path before, so it's worth pinning.
     */
    public function test_a_section_whose_schedule_has_passed_becomes_visible(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'title' => 'Timed Release',
            'is_published' => false, 'scheduled_at' => now()->subHour(),
        ]);
        $student = $this->enrolledStudent($course);

        $this->actingAs($student)->get("/courses/{$course->slug}")
            ->assertOk()
            ->assertSee('Timed Release');

        $this->assertTrue($section->fresh()->is_published);
    }

    public function test_future_scheduled_section_stays_hidden(): void
    {
        $course = Course::factory()->create(['is_active' => true]);
        Section::factory()->create([
            'course_id' => $course->id, 'title' => 'Not Yet Released',
            'is_published' => false, 'scheduled_at' => now()->addWeek(),
        ]);
        $student = $this->enrolledStudent($course);

        $this->actingAs($student)->get("/courses/{$course->slug}")
            ->assertOk()
            ->assertDontSee('Not Yet Released');
    }
}
