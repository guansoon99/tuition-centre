<?php

namespace Tests\Feature\Teacher;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FeedbackFile;
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
 * Files a teacher returns to one student.
 *
 * Two decisions in the design are load-bearing, and each has a test here that
 * fails loudly if it is undone:
 *
 *   1. Feedback lives in its own table. Sharing submission_files would have
 *      counted a teacher's file against the student's own upload cap and
 *      marked a student who never submitted as having submitted — both silent.
 *   2. Feedback is stored in a SIBLING folder of the student's, never inside
 *      it. The student's register step accepts any key under their own
 *      assignment prefix, so a nested layout would let them claim the
 *      teacher's file as their own work.
 */
class FeedbackFileTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Material $assignment;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web'])
            ->givePermissionTo('sections.manage');

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
            'max_files' => 2,
        ]);

        $this->teacher = $this->enrol(Enrollment::ROLE_TEACHER, 'teacher');
        $this->student = $this->enrol(Enrollment::ROLE_STUDENT, 'student');
    }

    private function enrol(string $roleOnCourse, string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $user->id,
            'role_on_course' => $roleOnCourse, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        return $user;
    }

    /** A submission row, with or without the work actually attached. */
    private function submission(bool $withFile = true): Submission
    {
        $submission = Submission::firstOrCreate(
            ['material_id' => $this->assignment->id, 'user_id' => $this->student->id],
            ['submitted_at' => now(), 'last_modified_at' => now()],
        );

        if ($withFile) {
            $path = CourseMedia::assignmentFolder(
                $this->course->id, $this->assignment->id, $this->student->id
            ).'/work.pdf';
            Storage::disk(PrivateFile::disk())->put($path, '%PDF-1.4 work');

            $submission->files()->create([
                'file_path' => $path,
                'original_name' => 'work.pdf',
                'size_bytes' => 13,
                'mime_type' => 'application/pdf',
                'uploaded_at' => now(),
            ]);
        }

        return $submission;
    }

    /**
     * Put a returned file in place without going through the teacher's POST.
     *
     * Student-side assertions need to act as the student only. Posting as the
     * teacher first and then switching actor does not take in this suite, and
     * the resulting 302 looks like an authorisation failure rather than a test
     * artefact.
     */
    private function seedFeedback(Submission $submission, string $name = 'marked.pdf'): FeedbackFile
    {
        $path = CourseMedia::feedbackFolder(
            $this->course->id, $this->assignment->id, $this->student->id
        ).'/'.\Illuminate\Support\Str::uuid().'.pdf';

        Storage::disk(PrivateFile::disk())->put($path, '%PDF-1.4 marked up');

        return $submission->feedbackFiles()->create([
            'file_path' => $path,
            'original_name' => $name,
            'size_bytes' => 18,
            'mime_type' => 'application/pdf',
            'uploaded_at' => now(),
            'uploaded_by_user_id' => $this->teacher->id,
        ]);
    }

    private function send(Submission $submission, ?User $as = null, string $name = 'marked.pdf')
    {
        return $this->actingAs($as ?? $this->teacher)
            ->post(route('feedback-files.store', $submission), [
                'feedback_files' => [
                    UploadedFile::fake()->createWithContent($name, '%PDF-1.4 marked up'),
                ],
            ]);
    }

    public function test_a_teacher_can_return_a_file(): void
    {
        $submission = $this->submission();

        $this->send($submission)->assertSessionHasNoErrors()->assertRedirect();

        $file = FeedbackFile::firstOrFail();
        $this->assertSame($submission->id, $file->submission_id);
        $this->assertSame('marked.pdf', $file->original_name);
        $this->assertSame($this->teacher->id, $file->uploaded_by_user_id);
        Storage::disk(PrivateFile::disk())->assertExists($file->file_path);
    }

    /** The rule you asked for: nothing to respond to, no feedback. */
    public function test_feedback_is_refused_for_a_student_who_has_not_submitted(): void
    {
        $submission = $this->submission(withFile: false);

        $this->send($submission)->assertSessionHasErrors('feedback');

        $this->assertSame(0, FeedbackFile::count());
    }

    /**
     * The trap a shared table would have set: a returned file must not eat
     * into the student's own upload allowance.
     */
    public function test_returned_files_do_not_count_against_the_students_cap(): void
    {
        $submission = $this->submission();
        $this->seedFeedback($submission);
        $this->seedFeedback($submission, 'rubric.pdf');

        $this->assertSame(1, $submission->files()->count());
        $this->assertSame(2, $submission->feedbackFiles()->count());

        // max_files is 2 and the student has used one, so one slot remains —
        // however many files the teacher has sent back.
        $this->actingAs($this->student)
            ->post(route('submissions.upload', $this->assignment), [
                'files' => [UploadedFile::fake()->create('second.pdf', 10, 'application/pdf')],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $submission->files()->count());
    }

    /** Nor may it make an unsubmitted student look submitted. */
    public function test_returned_files_do_not_make_a_student_look_submitted(): void
    {
        $submission = $this->submission();
        $this->seedFeedback($submission);

        $this->actingAs($this->student)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->assertSee('Submitted for grading');

        // Now strip the student's own work; only feedback remains.
        SubmissionFile::query()->delete();

        $this->actingAs($this->student)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->assertSee('No submissions have been made yet');
    }

    /**
     * Stored beside the student's folder, not inside it.
     *
     * Nested, the key would begin with the student's own presign prefix and
     * register() would accept it as their submission.
     */
    public function test_it_is_stored_outside_the_students_own_prefix(): void
    {
        $submission = $this->submission();
        $this->send($submission);

        $path = FeedbackFile::firstOrFail()->file_path;
        $studentPrefix = CourseMedia::assignmentFolder(
            $this->course->id, $this->assignment->id, $this->student->id
        ).'/';

        $this->assertStringStartsNotWith($studentPrefix, $path,
            'Feedback inside the student prefix could be registered as their own work.');
        $this->assertStringStartsWith(
            CourseMedia::feedbackFolder($this->course->id, $this->assignment->id, $this->student->id),
            $path,
        );
    }

    /** And the boundary really does refuse it. */
    public function test_a_student_cannot_register_a_feedback_file_as_their_own(): void
    {
        $submission = $this->submission();
        $this->seedFeedback($submission);

        $this->actingAs($this->student)
            ->postJson(route('submissions.register', $this->assignment), [
                'key' => FeedbackFile::firstOrFail()->file_path,
                'original_name' => 'mine.pdf',
            ])
            ->assertForbidden();
    }

    public function test_the_student_sees_it_on_their_page(): void
    {
        $this->seedFeedback($this->submission(), 'marked essay.pdf');

        $this->actingAs($this->student)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->assertSee('Feedback files')
            ->assertSee('marked essay.pdf')
            ->assertSee(route('feedback-files.download', FeedbackFile::firstOrFail()), false);
    }

    public function test_the_student_can_download_their_own_feedback(): void
    {
        $this->seedFeedback($this->submission());

        $this->actingAs($this->student)
            ->get(route('feedback-files.download', FeedbackFile::firstOrFail()))
            ->assertOk();
    }

    public function test_another_student_cannot_download_it(): void
    {
        $this->seedFeedback($this->submission());

        $outsider = $this->enrol(Enrollment::ROLE_STUDENT, 'student');

        $this->actingAs($outsider)
            ->get(route('feedback-files.download', FeedbackFile::firstOrFail()))
            ->assertForbidden();
    }

    public function test_a_student_cannot_return_feedback(): void
    {
        $submission = $this->submission();

        $this->send($submission, as: $this->student)->assertForbidden();

        $this->assertSame(0, FeedbackFile::count());
    }

    public function test_a_teacher_from_another_course_cannot_return_feedback(): void
    {
        $submission = $this->submission();

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('teacher');

        $this->send($submission, as: $outsider)->assertForbidden();

        $this->assertSame(0, FeedbackFile::count());
    }

    /**
     * The grading modal posts over fetch and stays open, so these need an
     * answer rather than a page. A redirect would be followed by fetch and
     * pull the whole roster back for nothing.
     */
    public function test_an_xhr_upload_gets_no_content_instead_of_a_redirect(): void
    {
        $submission = $this->submission();

        $this->actingAs($this->teacher)
            ->post(route('feedback-files.store', $submission), [
                'feedback_files' => [UploadedFile::fake()->createWithContent('marked.pdf', '%PDF-1.4 ok')],
            ], ['Accept' => 'application/json'])
            ->assertNoContent();

        $this->assertSame(1, FeedbackFile::count());
    }

    public function test_an_xhr_removal_gets_no_content_instead_of_a_redirect(): void
    {
        $this->seedFeedback($this->submission());

        $this->actingAs($this->teacher)
            ->delete(route('feedback-files.destroy', FeedbackFile::firstOrFail()), [], [
                'Accept' => 'application/json',
            ])
            ->assertNoContent();

        $this->assertSame(0, FeedbackFile::count());
    }

    /** A refusal has to come back as a message the modal can show. */
    public function test_an_xhr_refusal_comes_back_as_json(): void
    {
        $submission = $this->submission(withFile: false);

        $this->actingAs($this->teacher)
            ->post(route('feedback-files.store', $submission), [
                'feedback_files' => [UploadedFile::fake()->createWithContent('marked.pdf', '%PDF-1.4 ok')],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJson(['message' => 'This student has not submitted anything yet.']);
    }

    /** A plain form post still redirects, for the no-JavaScript path. */
    public function test_a_plain_post_still_redirects(): void
    {
        $this->send($this->submission())->assertRedirect();
    }

    public function test_removing_a_feedback_file_deletes_the_object(): void
    {
        $this->send($this->submission());
        $file = FeedbackFile::firstOrFail();

        $this->actingAs($this->teacher)
            ->delete(route('feedback-files.destroy', $file))
            ->assertRedirect();

        $this->assertSame(0, FeedbackFile::count());
        Storage::disk(PrivateFile::disk())->assertMissing($file->file_path);
    }

    public function test_an_executable_is_refused(): void
    {
        $submission = $this->submission();

        $this->actingAs($this->teacher)
            ->post(route('feedback-files.store', $submission), [
                'feedback_files' => [UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload')],
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, FeedbackFile::count());
    }

    /**
     * Feedback sits under course-media, which the orphan sweep scans. Left out
     * of the referenced set it would delete every returned file a day later.
     */
    public function test_the_orphan_sweep_leaves_feedback_alone(): void
    {
        $this->seedFeedback($this->submission());
        $path = FeedbackFile::firstOrFail()->file_path;

        // The sweep only touches objects older than its cutoff.
        $this->travelTo(now()->addDays(2));
        $this->artisan('submissions:sweep-orphans')->assertExitCode(0);

        Storage::disk(PrivateFile::disk())->assertExists($path);
    }

    /** Deleting the section takes the work and the feedback about it. */
    public function test_deleting_the_section_removes_returned_files(): void
    {
        $this->send($this->submission());
        $path = FeedbackFile::firstOrFail()->file_path;

        $this->actingAs($this->teacher)
            ->delete(route('sections.destroy', $this->assignment->section))
            ->assertRedirect();

        $this->assertSame(0, FeedbackFile::count());
        Storage::disk(PrivateFile::disk())->assertMissing($path);
    }
}
