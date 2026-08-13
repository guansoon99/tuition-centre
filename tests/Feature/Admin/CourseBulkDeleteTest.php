<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\Material;
use App\Models\Section;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bulk course delete is the app's only data-destroying admin action, so the
 * guarantees are worth pinning down: rows really go all the way down the FK
 * chain, files really leave the disk, and the course's unique code is freed
 * for reuse.
 */
class CourseBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    /**
     * Builds a course with a section, a material carrying a real file, and a
     * student submission carrying its own file. Returns the pieces the tests
     * need to assert on.
     */
    private function makeCourseWithSubmission(): array
    {
        $disk = Storage::disk(config('filesystems.default'));

        $course = Course::factory()->create();
        $section = Section::factory()->create(['course_id' => $course->id]);

        $materialPath = "materials/{$course->id}/{$section->id}/doc.pdf";
        $disk->put($materialPath, 'pdf-bytes');

        $material = Material::factory()->create([
            'section_id' => $section->id,
            'type' => Material::TYPE_ASSIGNMENT,
            'file_path' => $materialPath,
        ]);

        $student = User::factory()->create();
        $student->assignRole('student');

        $submission = Submission::create([
            'material_id' => $material->id,
            'user_id' => $student->id,
            'submitted_at' => now(),
        ]);

        $submissionPath = "submissions/{$course->id}/{$material->id}/{$student->id}/hw.pdf";
        $disk->put($submissionPath, 'homework-bytes');

        $submissionFile = SubmissionFile::create([
            'submission_id' => $submission->id,
            'file_path' => $submissionPath,
            'original_name' => 'hw.pdf',
            'size_bytes' => 14,
            'mime_type' => 'application/pdf',
            'uploaded_at' => now(),
        ]);

        return compact(
            'course', 'section', 'material', 'submission', 'submissionFile',
            'materialPath', 'submissionPath', 'disk'
        );
    }

    public function test_bulk_delete_hard_deletes_course_and_cascades_every_child_row(): void
    {
        ['course' => $course, 'section' => $section, 'material' => $material,
         'submission' => $submission, 'submissionFile' => $submissionFile] = $this->makeCourseWithSubmission();

        $this->actingAs($this->admin)
            ->post('/courses/bulk-destroy', ['ids' => [$course->id]])
            ->assertRedirect();

        // Hard delete, not soft — the row is physically gone. A soft delete
        // emits an UPDATE, which fires no ON DELETE CASCADE at all.
        $this->assertNull(Course::withTrashed()->find($course->id));

        // ...so the whole chain unwound with it.
        $this->assertNull(Section::withTrashed()->find($section->id));
        $this->assertNull(Material::withTrashed()->find($material->id));
        $this->assertDatabaseMissing('submissions', ['id' => $submission->id]);
        $this->assertDatabaseMissing('submission_files', ['id' => $submissionFile->id]);
    }

    /**
     * Regression guard for the soft-delete trap: if this ever goes back to
     * delete(), children survive as live rows pointing at files we deleted.
     */
    public function test_no_orphan_child_rows_survive_the_delete(): void
    {
        ['course' => $course] = $this->makeCourseWithSubmission();

        $this->actingAs($this->admin)
            ->post('/courses/bulk-destroy', ['ids' => [$course->id]]);

        $this->assertSame(0, Section::withTrashed()->where('course_id', $course->id)->count());
        $this->assertSame(0, \DB::table('enrollments')->where('course_id', $course->id)->count());
        $this->assertSame(0, \DB::table('course_views')->where('course_id', $course->id)->count());
    }

    public function test_bulk_delete_removes_material_and_submission_files_from_disk(): void
    {
        ['course' => $course, 'materialPath' => $materialPath,
         'submissionPath' => $submissionPath, 'disk' => $disk] = $this->makeCourseWithSubmission();

        $this->assertTrue($disk->exists($materialPath));
        $this->assertTrue($disk->exists($submissionPath));

        $this->actingAs($this->admin)
            ->post('/courses/bulk-destroy', ['ids' => [$course->id]]);

        $this->assertFalse($disk->exists($materialPath), 'Material file should be unlinked.');
        $this->assertFalse($disk->exists($submissionPath), 'Submission file should be unlinked.');
    }

    /**
     * Files hanging off already-soft-deleted sections/materials still get
     * cascade-removed at the DB level, so they have to be unlinked too —
     * otherwise the rows vanish and the paths become unreachable forever.
     */
    public function test_bulk_delete_cleans_files_behind_soft_deleted_children(): void
    {
        ['course' => $course, 'section' => $section,
         'materialPath' => $materialPath, 'disk' => $disk] = $this->makeCourseWithSubmission();

        $section->delete(); // soft
        $this->assertTrue($disk->exists($materialPath));

        $this->actingAs($this->admin)
            ->post('/courses/bulk-destroy', ['ids' => [$course->id]]);

        $this->assertFalse($disk->exists($materialPath), 'File under a soft-deleted section should still be unlinked.');
    }

    /**
     * slug and code are plain unique columns with no deleted_at awareness,
     * so a soft-deleted course would squat its code forever and the admin
     * could never recreate it. Hard delete frees them.
     */
    public function test_deleted_course_code_can_be_reused(): void
    {
        $course = Course::factory()->create(['code' => 'CHEM-101', 'slug' => 'chem-101']);

        $this->actingAs($this->admin)
            ->post('/courses/bulk-destroy', ['ids' => [$course->id]]);

        $fresh = Course::factory()->create(['code' => 'CHEM-101', 'slug' => 'chem-101']);

        $this->assertDatabaseHas('courses', ['id' => $fresh->id, 'code' => 'CHEM-101']);
    }

    public function test_bulk_delete_requires_the_courses_delete_permission(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');
        $course = Course::factory()->create();

        $this->actingAs($student)
            ->post('/courses/bulk-destroy', ['ids' => [$course->id]])
            ->assertForbidden();

        $this->assertNotNull(Course::find($course->id));
    }
}
