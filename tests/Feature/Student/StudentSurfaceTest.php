<?php

namespace Tests\Feature\Student;

use App\Models\AccessLog;
use App\Models\Course;
use App\Models\Material;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private Course $enrolled;
    private Course $unrelated;
    private Section $section;
    private Material $pdf;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->student = User::factory()->create(['username' => 'STU001', 'password' => 'secret']);
        $this->student->assignRole('student');

        $this->enrolled = Course::factory()->create(['code' => 'PA-1', 'name' => 'Pengajian Am 1']);
        $this->unrelated = Course::factory()->create(['code' => 'OTHER', 'name' => 'Other Course']);

        $this->student->enrolledCourses()->attach($this->enrolled, ['enrolled_at' => now(), 'is_active' => true]);

        $this->section = Section::factory()->create(['course_id' => $this->enrolled->id, 'is_published' => true]);
        $this->pdf = Material::factory()->create([
            'section_id' => $this->section->id,
            'is_published' => true,
        ]);
    }

    public function test_home_lists_only_enrolled_courses_for_student(): void
    {
        $response = $this->actingAs($this->student)->get('/');

        $response->assertOk()
            ->assertSee('Pengajian Am 1')
            ->assertDontSee('Other Course');
    }

    public function test_student_can_view_enrolled_course_detail(): void
    {
        $response = $this->actingAs($this->student)->get('/courses/'.$this->enrolled->slug);

        $response->assertOk()
            ->assertSee($this->section->title)
            ->assertSee($this->pdf->title);
    }

    public function test_student_cannot_view_unrelated_course(): void
    {
        $this->actingAs($this->student)
            ->get('/courses/'.$this->unrelated->slug)
            ->assertForbidden();
    }

    public function test_unpublished_sections_are_hidden_from_students(): void
    {
        $hidden = Section::factory()->create([
            'course_id' => $this->enrolled->id,
            'title' => 'HIDDEN_SECTION_X',
            'is_published' => false,
        ]);

        $this->actingAs($this->student)
            ->get('/courses/'.$this->enrolled->slug)
            ->assertOk()
            ->assertDontSee('HIDDEN_SECTION_X');
    }

    public function test_visiting_course_updates_last_accessed_at_for_recently_accessed(): void
    {
        $this->assertNull(
            $this->student->enrollments()->where('course_id', $this->enrolled->id)->value('last_accessed_at')
        );

        $this->actingAs($this->student)->get('/courses/'.$this->enrolled->slug);

        $this->assertNotNull(
            $this->student->enrollments()->where('course_id', $this->enrolled->id)->value('last_accessed_at')
        );
    }

    public function test_pdf_download_redirects_and_logs_access(): void
    {
        $this->assertSame(0, AccessLog::count());

        $response = $this->actingAs($this->student)
            ->get('/materials/'.$this->pdf->id.'/download');

        $response->assertStatus(302);

        $this->assertSame(1, AccessLog::count());
        $log = AccessLog::first();
        $this->assertSame($this->student->id, $log->user_id);
        $this->assertSame($this->pdf->id, $log->material_id);
        $this->assertSame(AccessLog::ACTION_DOWNLOAD, $log->action);
    }

    public function test_student_cannot_download_material_from_unrelated_course(): void
    {
        $otherSection = Section::factory()->create(['course_id' => $this->unrelated->id]);
        $otherPdf = Material::factory()->create(['section_id' => $otherSection->id]);

        $this->actingAs($this->student)
            ->get('/materials/'.$otherPdf->id.'/download')
            ->assertForbidden();

        $this->assertSame(0, AccessLog::count());
    }

    public function test_external_link_logs_view_action_and_redirects_to_external_url(): void
    {
        $link = Material::factory()->externalLink()->create(['section_id' => $this->section->id]);

        $response = $this->actingAs($this->student)
            ->get('/materials/'.$link->id.'/download');

        $response->assertStatus(302);
        $response->assertRedirect($link->external_url);

        $log = AccessLog::where('material_id', $link->id)->first();
        $this->assertSame(AccessLog::ACTION_VIEW, $log->action);
    }

    public function test_recently_accessed_section_appears_after_visiting_course(): void
    {
        // Initial home: no recently accessed.
        $this->actingAs($this->student)->get('/')->assertDontSee('Recently accessed');

        // Visit the course → bumps last_accessed_at.
        $this->actingAs($this->student)->get('/courses/'.$this->enrolled->slug);

        $this->actingAs($this->student)->get('/')->assertSee('Recently accessed');
    }
}
