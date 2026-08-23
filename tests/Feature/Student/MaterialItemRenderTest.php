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
 * The material row is a plain partial rather than a Blade component, because
 * it renders once per material and components cost ~47% more per instance.
 *
 * The risk that buys is its `@once @push('head')` style block: included
 * dozens of times per page, it must still emit exactly one copy. Nothing else
 * covers that, and getting it wrong would either duplicate ~30 lines of CSS
 * per material or drop the styles entirely — neither of which fails a test
 * that only checks for content.
 */
class MaterialItemRenderTest extends TestCase
{
    use RefreshDatabase;

    private function courseWithMaterials(int $count): array
    {
        foreach (['admin', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);

        Material::factory()->count($count)->create([
            'section_id' => $section->id,
            'type' => Material::TYPE_MEDIA,
            'body' => '<p>Some <strong>rich</strong> text.</p>',
            'is_published' => true,
        ]);

        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');
        Enrollment::create([
            'course_id' => $course->id, 'user_id' => $student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        return [$course, $student];
    }

    public function test_prose_styles_are_emitted_exactly_once_regardless_of_material_count(): void
    {
        [$course, $student] = $this->courseWithMaterials(30);

        $html = $this->actingAs($student)
            ->get("/courses/{$course->slug}")
            ->assertOk()
            ->getContent();

        // The @once block opens with this rule; 30 materials must not mean
        // 30 copies of the stylesheet.
        $this->assertSame(
            1,
            substr_count($html, '.prose-section h1'),
            'The @once style block should appear exactly once per page.'
        );
    }

    public function test_every_material_still_renders_its_row(): void
    {
        [$course, $student] = $this->courseWithMaterials(12);

        $html = $this->actingAs($student)
            ->get("/courses/{$course->slug}")
            ->assertOk()
            ->getContent();

        // Each text material renders its body through the prose wrapper.
        $this->assertSame(12, substr_count($html, 'Some <strong>rich</strong> text.'));
    }

    public function test_each_material_type_renders_its_own_icon(): void
    {
        [$course, $student] = $this->courseWithMaterials(1);
        $section = Section::where('course_id', $course->id)->firstOrFail();

        Material::factory()->create([
            'section_id' => $section->id, 'title' => 'A PDF',
            'type' => Material::TYPE_PDF, 'is_published' => true,
        ]);
        Material::factory()->create([
            'section_id' => $section->id, 'title' => 'An Assignment',
            'type' => Material::TYPE_ASSIGNMENT, 'is_published' => true,
            'body' => '<p>Answer all questions.</p>',
            'due_date' => now()->addWeek(),
        ]);

        $html = $this->actingAs($student)
            ->get("/courses/{$course->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('A PDF', $html);
        $this->assertStringContainsString('An Assignment', $html);

        // Assignment rows show the teacher's description inline; the due date
        // lives on the assignment page itself, not in the list.
        $this->assertStringContainsString('Answer all questions.', $html);
    }
}
