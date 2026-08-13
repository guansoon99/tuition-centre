<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Support\PrivateFile;
use App\Support\PublicFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Student assignment uploads.
 *
 * The controller deliberately writes files BEFORE opening its transaction —
 * storing them inside it would hold a database write lock across the upload
 * (an R2 round-trip in production), which on SQLite blocks every other write
 * in the app. Measured at 6ms -> 2600ms for concurrent page views.
 *
 * The all-or-nothing guarantee is preserved by deleting stored files if the
 * insert fails, which is what several of these cover.
 */
class SubmissionUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Material $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        Storage::fake(PrivateFile::disk());
        Storage::fake(PublicFile::disk());

        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        $this->assignment = Material::factory()->create([
            'section_id' => $section->id,
            'type' => Material::TYPE_ASSIGNMENT,
            'is_published' => true,
            'due_date' => now()->addWeek(),
            'max_files' => 3,
            'max_file_size_gb' => 1,
        ]);

        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');
        Enrollment::create([
            'course_id' => $course->id, 'user_id' => $this->student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    private function upload(array $files)
    {
        return $this->actingAs($this->student)
            ->post(route('submissions.upload', $this->assignment), ['files' => $files]);
    }

    public function test_a_student_can_upload_files(): void
    {
        $this->upload([
            UploadedFile::fake()->create('essay.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->image('photo.jpg'),
        ])->assertRedirect();

        $submission = Submission::where('user_id', $this->student->id)->firstOrFail();
        $this->assertSame(2, $submission->files()->count());

        foreach ($submission->files as $file) {
            Storage::disk(PrivateFile::disk())->assertExists($file->file_path);
        }
    }

    public function test_original_filename_and_metadata_are_recorded(): void
    {
        $this->upload([UploadedFile::fake()->create('My Homework.pdf', 12, 'application/pdf')]);

        $file = SubmissionFile::firstOrFail();
        $this->assertSame('My Homework.pdf', $file->original_name);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertGreaterThan(0, $file->size_bytes);

        // Stored under a UUID, not the user-supplied name.
        $this->assertStringNotContainsString('My Homework', $file->file_path);
    }

    public function test_files_are_stored_privately_not_on_the_public_disk(): void
    {
        $this->upload([UploadedFile::fake()->image('answer.png')]);

        $path = SubmissionFile::firstOrFail()->file_path;
        Storage::disk(PrivateFile::disk())->assertExists($path);
        Storage::disk(PublicFile::disk())->assertMissing($path);
    }

    public function test_uploading_twice_appends_rather_than_replacing(): void
    {
        $this->upload([UploadedFile::fake()->create('one.pdf', 10, 'application/pdf')]);
        $this->upload([UploadedFile::fake()->create('two.pdf', 10, 'application/pdf')]);

        $this->assertSame(1, Submission::count(), 'Should reuse the same submission row.');
        $this->assertSame(2, SubmissionFile::count());
    }

    public function test_the_max_files_cap_is_enforced(): void
    {
        $this->upload([
            UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'),
            UploadedFile::fake()->create('b.pdf', 5, 'application/pdf'),
        ]);

        // max_files is 3; two more would make four.
        $this->upload([
            UploadedFile::fake()->create('c.pdf', 5, 'application/pdf'),
            UploadedFile::fake()->create('d.pdf', 5, 'application/pdf'),
        ])->assertSessionHasErrors('files');

        $this->assertSame(2, SubmissionFile::count());
    }

    /**
     * Files are written before the rows, so a failed insert has to clean them
     * up — otherwise every failure leaks an orphan into storage.
     */
    public function test_no_orphan_files_are_left_when_the_insert_fails(): void
    {
        $this->upload([UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')]);

        $before = Storage::disk(PrivateFile::disk())->allFiles();
        $this->assertCount(1, $before);

        // Force the transaction to fail: drop the table the insert targets.
        \Schema::drop('submission_files');

        try {
            $this->upload([UploadedFile::fake()->create('b.pdf', 5, 'application/pdf')]);
        } catch (\Throwable) {
            // expected
        }

        $after = Storage::disk(PrivateFile::disk())->allFiles();
        $this->assertSame(
            $before,
            $after,
            'A failed insert left its uploaded file behind.'
        );
    }

    public function test_uploads_are_blocked_once_past_the_due_date(): void
    {
        $this->assignment->update(['due_date' => now()->subDay()]);

        $this->upload([UploadedFile::fake()->create('late.pdf', 5, 'application/pdf')])
            ->assertSessionHasErrors('files');

        $this->assertSame(0, SubmissionFile::count());
        $this->assertSame([], Storage::disk(PrivateFile::disk())->allFiles());
    }

    public function test_a_student_from_another_course_cannot_upload(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('student');

        $this->actingAs($outsider)
            ->post(route('submissions.upload', $this->assignment), [
                'files' => [UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')],
            ])
            ->assertForbidden();

        $this->assertSame(0, SubmissionFile::count());
    }

    public function test_disallowed_file_types_are_rejected(): void
    {
        $this->upload([UploadedFile::fake()->create('script.php', 5, 'application/x-php')])
            ->assertSessionHasErrors('files.0');

        $this->assertSame(0, SubmissionFile::count());
    }
}
