<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Support\CourseMedia;
use App\Support\PrivateFile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * When a student may still change their own submission.
 *
 * Two rules, checked at the routes rather than in the view: hiding a control
 * is a courtesy, but the request is what has to be refused. Every write path
 * is covered separately — the proxied upload, the two halves of the direct
 * upload, and removal — because they are four different methods and a guard
 * added to one proves nothing about the others.
 */
class SubmissionLockingTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Material $assignment;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(PrivateFile::disk());

        $this->course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $this->course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        $this->assignment = Material::factory()->create([
            'section_id' => $section->id,
            'type' => Material::TYPE_ASSIGNMENT,
            'is_published' => true,
            'due_date' => now()->addWeek(),
            'max_files' => 5,
        ]);

        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    private function submission(): Submission
    {
        $submission = Submission::firstOrCreate(
            ['material_id' => $this->assignment->id, 'user_id' => $this->student->id],
            ['submitted_at' => now(), 'last_modified_at' => now()],
        );

        $path = CourseMedia::assignmentFolder(
            $this->course->id, $this->assignment->id, $this->student->id
        ).'/work.pdf';
        Storage::disk(PrivateFile::disk())->put($path, '%PDF-1.4 work');

        $submission->files()->firstOrCreate([
            'file_path' => $path,
            'original_name' => 'work.pdf',
            'size_bytes' => 13,
            'mime_type' => 'application/pdf',
            'uploaded_at' => now(),
        ]);

        return $submission;
    }

    private function closeTheDeadline(): void
    {
        $this->assignment->update(['due_date' => now()->subDay()]);
    }

    private function markIt(): void
    {
        $this->submission()->update(['grade' => '85', 'graded_at' => now()]);
    }

    // ---- Past the deadline -------------------------------------------------

    public function test_past_due_blocks_the_proxied_upload(): void
    {
        $this->submission();
        $this->closeTheDeadline();

        $this->actingAs($this->student)
            ->post(route('submissions.upload', $this->assignment), [
                'files' => [UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')],
            ])
            ->assertSessionHasErrors('files');

        $this->assertSame(1, SubmissionFile::count(), 'No file should have been added.');
    }

    public function test_past_due_blocks_presigning(): void
    {
        $this->submission();
        $this->closeTheDeadline();

        $this->actingAs($this->student)
            ->postJson(route('submissions.presign', $this->assignment), [
                'size' => 1024, 'content_type' => 'application/pdf',
            ])
            ->assertStatus(422);
    }

    /** The deadline can pass between presigning and registering. */
    public function test_past_due_blocks_registering_an_already_uploaded_object(): void
    {
        $this->submission();
        $key = CourseMedia::assignmentFolder(
            $this->course->id, $this->assignment->id, $this->student->id
        ).'/sneaked.pdf';
        Storage::disk(PrivateFile::disk())->put($key, '%PDF-1.4 late');

        $this->closeTheDeadline();

        $this->actingAs($this->student)
            ->postJson(route('submissions.register', $this->assignment), [
                'key' => $key, 'original_name' => 'sneaked.pdf',
            ])
            ->assertStatus(422);

        $this->assertSame(1, SubmissionFile::count());
        Storage::disk(PrivateFile::disk())->assertMissing($key);
    }

    public function test_past_due_blocks_removing_a_file(): void
    {
        $this->submission();
        $this->closeTheDeadline();

        $this->actingAs($this->student)
            ->delete(route('submission-files.destroy', SubmissionFile::firstOrFail()))
            ->assertSessionHasErrors('files');

        $this->assertSame(1, SubmissionFile::count(), 'The file should still be there.');
    }

    // ---- Once marked -------------------------------------------------------

    public function test_grading_blocks_the_proxied_upload(): void
    {
        $this->markIt();

        $this->actingAs($this->student)
            ->post(route('submissions.upload', $this->assignment), [
                'files' => [UploadedFile::fake()->create('extra.pdf', 10, 'application/pdf')],
            ])
            ->assertSessionHasErrors('files');

        $this->assertSame(1, SubmissionFile::count(), 'No file should have been added after marking.');
    }

    public function test_grading_blocks_presigning(): void
    {
        $this->markIt();

        $this->actingAs($this->student)
            ->postJson(route('submissions.presign', $this->assignment), [
                'size' => 1024, 'content_type' => 'application/pdf',
            ])
            ->assertStatus(422);
    }

    public function test_grading_blocks_registering(): void
    {
        $this->markIt();

        $key = CourseMedia::assignmentFolder(
            $this->course->id, $this->assignment->id, $this->student->id
        ).'/after-marking.pdf';
        Storage::disk(PrivateFile::disk())->put($key, '%PDF-1.4 after');

        $this->actingAs($this->student)
            ->postJson(route('submissions.register', $this->assignment), [
                'key' => $key, 'original_name' => 'after-marking.pdf',
            ])
            ->assertStatus(422);

        $this->assertSame(1, SubmissionFile::count());
    }

    public function test_grading_blocks_removing_a_file(): void
    {
        $this->markIt();

        $this->actingAs($this->student)
            ->delete(route('submission-files.destroy', SubmissionFile::firstOrFail()))
            ->assertSessionHasErrors('files');

        $this->assertSame(1, SubmissionFile::count(), 'Marked work should not be removable.');
    }

    // ---- What the page shows ----------------------------------------------

    /**
     * The controls go, the files stay.
     *
     * Losing sight of the work that was marked would be worse than the extra
     * buttons — the student still needs to read and download it.
     */
    public function test_a_marked_submission_still_lists_its_files(): void
    {
        $this->markIt();

        $html = $this->actingAs($this->student)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('work.pdf', $html, 'The marked work should still be listed.');
        $this->assertStringContainsString(
            route('submission-files.download', SubmissionFile::firstOrFail()),
            $html,
            'And still be downloadable.',
        );
        $this->assertStringNotContainsString('name="files[]"', $html, 'But not replaceable.');
        $this->assertStringNotContainsString('>
                                                Remove
', $html);
        $this->assertStringContainsString('has been graded and can no longer be changed', $html,
            'And the page should say why the controls are gone.');
    }

    /** The teacher's copy needs no explanation of controls it never had. */
    public function test_the_teachers_view_does_not_carry_the_student_notice(): void
    {
        $this->markIt();

        // The route sits behind permission:sections.manage.
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web'])
            ->givePermissionTo('sections.manage');

        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $html = $this->actingAs($teacher)
            ->get(route('submissions.grade-modal', Submission::firstOrFail()))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('work.pdf', $html);
        $this->assertStringNotContainsString('can no longer be changed', $html);
    }

    // ---- Un-graded again ---------------------------------------------------

    /**
     * Clearing a grade reopens the submission.
     *
     * The lock keys off graded_at, and the teacher clearing both fields nulls
     * it — so a mark entered by mistake is not a one-way door for the student.
     */
    public function test_clearing_the_grade_reopens_the_submission(): void
    {
        $this->markIt();

        // What the teacher's route does when both fields are emptied.
        Submission::firstOrFail()->update([
            'grade' => null, 'comment' => null, 'graded_at' => null, 'graded_by_user_id' => null,
        ]);

        $this->actingAs($this->student)
            ->post(route('submissions.upload', $this->assignment), [
                'files' => [UploadedFile::fake()->create('revised.pdf', 10, 'application/pdf')],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, SubmissionFile::count(), 'The student should be able to submit again.');
    }

    public function test_clearing_the_grade_reopens_removal_too(): void
    {
        $this->markIt();
        Submission::firstOrFail()->update([
            'grade' => null, 'comment' => null, 'graded_at' => null, 'graded_by_user_id' => null,
        ]);

        $this->actingAs($this->student)
            ->delete(route('submission-files.destroy', SubmissionFile::firstOrFail()))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, SubmissionFile::count());
    }

    /** And the page offers the controls again. */
    public function test_a_reopened_submission_shows_the_upload_form_again(): void
    {
        $this->markIt();
        Submission::firstOrFail()->update([
            'grade' => null, 'comment' => null, 'graded_at' => null, 'graded_by_user_id' => null,
        ]);

        $html = $this->actingAs($this->student)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="files[]"', $html);
        $this->assertStringNotContainsString('can no longer be changed', $html);
    }

    /**
     * The deadline is a separate lock. Un-grading does not reopen an
     * assignment whose due date has passed.
     */
    public function test_clearing_the_grade_does_not_reopen_a_closed_deadline(): void
    {
        $this->markIt();
        Submission::firstOrFail()->update([
            'grade' => null, 'comment' => null, 'graded_at' => null, 'graded_by_user_id' => null,
        ]);
        $this->closeTheDeadline();

        $this->actingAs($this->student)
            ->post(route('submissions.upload', $this->assignment), [
                'files' => [UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')],
            ])
            ->assertSessionHasErrors('files');

        $this->assertSame(1, SubmissionFile::count());
    }

    // ---- Still open --------------------------------------------------------

    /** The guards must not lock a submission that is simply in progress. */
    public function test_an_open_unmarked_assignment_is_still_editable(): void
    {
        $this->submission();

        $this->actingAs($this->student)
            ->post(route('submissions.upload', $this->assignment), [
                'files' => [UploadedFile::fake()->create('second.pdf', 10, 'application/pdf')],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, SubmissionFile::count());
    }
}
