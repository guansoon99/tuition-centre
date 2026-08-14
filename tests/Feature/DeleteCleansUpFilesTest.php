<?php

namespace Tests\Feature;

use App\Models\BannerSlide;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Support\CourseMedia;
use App\Support\PrivateFile;
use App\Support\PublicFile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every delete in the app must take its files with it.
 *
 * Storage is the one place a bug leaves no trace: a stranded object costs
 * money quietly, forever, and nothing surfaces it. Each delete path therefore
 * gets an explicit test rather than being assumed to work because a similar
 * one does.
 */
class DeleteCleansUpFilesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private Course $course;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $staff = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $staff->givePermissionTo('sections.manage');

        Storage::fake(PrivateFile::disk());
        Storage::fake(PublicFile::disk());

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->course = Course::factory()->create(['is_active' => true]);
        $this->section = Section::factory()->create([
            'course_id' => $this->course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);

        $this->teacher = User::factory()->create(['is_active' => true]);
        $this->teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    private function store(string $disk, string $path): string
    {
        Storage::disk($disk)->put($path, 'bytes');

        return $path;
    }

    // Announcement image cleanup is covered in AnnouncementImageTest
    // ("deleting an announcement removes its private file") — not repeated here.

    public function test_deleting_a_banner_slide_removes_its_image(): void
    {
        $path = $this->store(PublicFile::disk(), 'banner-slides/b.webp');
        $slide = BannerSlide::create([
            'image_path' => $path, 'sort_order' => 1, 'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('banner.destroy', $slide))
            ->assertRedirect();

        Storage::disk(PublicFile::disk())->assertMissing($path);
    }

    public function test_deleting_a_material_removes_its_pdf(): void
    {
        $path = $this->store(PrivateFile::disk(), 'materials/1/1/doc.pdf');
        $m = Material::factory()->create([
            'section_id' => $this->section->id, 'type' => Material::TYPE_PDF, 'file_path' => $path,
        ]);

        $this->actingAs($this->teacher)
            ->delete(route('materials.destroy', $m))
            ->assertRedirect();

        Storage::disk(PrivateFile::disk())->assertMissing($path);
    }

    public function test_a_student_removing_a_submission_file_removes_the_object(): void
    {
        [$student, $file] = $this->submissionFile();

        $this->actingAs($student)
            ->delete(route('submission-files.destroy', $file))
            ->assertRedirect();

        Storage::disk(PrivateFile::disk())->assertMissing($file->file_path);
    }

    /**
     * The case that motivated all of this. A section delete cascades through
     * materials to submissions and their files — none of which were being
     * cleaned up, because a soft delete fires no FK cascade at all.
     */
    public function test_deleting_a_section_removes_every_file_beneath_it(): void
    {
        $pdf = $this->store(PrivateFile::disk(), 'materials/9/9/lesson.pdf');
        $embedded = 'ffff1111-2222-3333-4444-555566667777.webp';
        $this->store(PrivateFile::disk(), CourseMedia::folder($this->course->id).'/'.$embedded);

        Material::factory()->create([
            'section_id' => $this->section->id, 'type' => Material::TYPE_PDF, 'file_path' => $pdf,
        ]);
        Material::factory()->create([
            'section_id' => $this->section->id, 'type' => Material::TYPE_TEXT,
            'body' => '<img src="/courses/'.$this->course->id.'/media/'.$embedded.'">',
        ]);

        [, $submissionFile] = $this->submissionFile();

        $this->actingAs($this->teacher)
            ->delete(route('sections.destroy', $this->section))
            ->assertRedirect();

        $disk = Storage::disk(PrivateFile::disk());
        $disk->assertMissing($pdf);
        $disk->assertMissing(CourseMedia::folder($this->course->id).'/'.$embedded);
        $disk->assertMissing($submissionFile->file_path);

        // And the rows really are gone, not just hidden.
        $this->assertDatabaseMissing('sections', ['id' => $this->section->id]);
        $this->assertDatabaseMissing('submission_files', ['id' => $submissionFile->id]);
    }

    /** An assignment with a student submission attached to this section. */
    private function submissionFile(): array
    {
        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $assignment = Material::factory()->create([
            'section_id' => $this->section->id,
            'type' => Material::TYPE_ASSIGNMENT,
            'due_date' => now()->addWeek(),
        ]);

        $path = $this->store(
            PrivateFile::disk(),
            'submissions/'.$this->course->id.'/'.$assignment->id.'/'.$student->id.'/work.pdf',
        );

        $submission = Submission::create([
            'material_id' => $assignment->id, 'user_id' => $student->id, 'submitted_at' => now(),
        ]);

        $file = $submission->files()->create([
            'file_path' => $path, 'original_name' => 'work.pdf',
            'size_bytes' => 5, 'mime_type' => 'application/pdf', 'uploaded_at' => now(),
        ]);

        return [$student, $file];
    }
}
