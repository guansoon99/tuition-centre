<?php

namespace Tests\Feature\Teacher;

use App\Models\Course;
use App\Models\Material;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\TestCase;

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

        $this->teacher = User::factory()->create(['username' => 'tA', 'password' => 'p']);
        $this->teacher->assignRole('teacher');

        $this->otherTeacher = User::factory()->create(['username' => 'tB', 'password' => 'p']);
        $this->otherTeacher->assignRole('teacher');

        $this->course = Course::factory()->create();
        $this->course->teachers()->attach($this->teacher, ['assigned_at' => now()]);
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
            ->assertRedirect(route('courses.edit', [$this->course, 'tab' => 'sections']));

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
