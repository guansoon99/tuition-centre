<?php

namespace Tests\Feature;

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
 * Coloured text has to reach the database through the real save route.
 *
 * HtmlSanitizerColorTest covers the sanitizer in isolation; this covers the
 * controller, because the body passes through HtmlSanitizer::clean() there and
 * a whitelist that is right in a unit test is worth nothing if the request
 * never reaches it. The reported symptom was specifically that colour worked
 * on plain text and vanished on bold, so both are asserted together — a test
 * that only checked bold could pass on a build where colour was broken
 * everywhere.
 */
class MaterialColorSurvivesSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web'])
            ->givePermissionTo('sections.manage');

        $course = Course::factory()->create(['is_active' => true]);
        $this->section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);

        $this->teacher = User::factory()->create(['is_active' => true]);
        $this->teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $course->id, 'user_id' => $this->teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    /** Verbatim Quill 2.0.2 output: bold carries the colour on the <strong>. */
    private const BODY = '<p><strong style="color: rgb(230, 0, 0);">bold red</strong>'
        .'<span style="color: rgb(0, 0, 230);">plain blue</span></p>';

    public function test_colour_survives_creating_a_material(): void
    {
        $this->actingAs($this->teacher)
            ->post(route('materials.store', $this->section), [
                'title' => 'Coloured', 'type' => Material::TYPE_TEXT, 'body' => self::BODY,
            ])->assertSessionHasNoErrors();

        $body = Material::firstOrFail()->body;

        $this->assertStringContainsString('rgb(230,0,0)', str_replace(' ', '', $body),
            'Bold text lost its colour on save.');
        $this->assertStringContainsString('rgb(0,0,230)', str_replace(' ', '', $body),
            'Plain text lost its colour on save.');
    }

    public function test_colour_survives_updating_a_material(): void
    {
        $material = Material::factory()->create([
            'section_id' => $this->section->id, 'type' => Material::TYPE_TEXT, 'body' => '<p>plain</p>',
        ]);

        $this->actingAs($this->teacher)
            ->patch(route('materials.update', $material), [
                'title' => 'Coloured', 'type' => Material::TYPE_TEXT, 'body' => self::BODY,
            ])->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            'rgb(230,0,0)',
            str_replace(' ', '', $material->fresh()->body),
            'Bold text lost its colour on update.',
        );
    }
}
