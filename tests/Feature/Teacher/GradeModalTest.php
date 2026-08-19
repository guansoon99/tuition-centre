<?php

namespace Tests\Feature\Teacher;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\Submission;
use App\Models\User;
use App\Support\CourseMedia;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Grading moved off the roster row and into a modal.
 *
 * A row was carrying four stacked controls — the file list, the grade, the
 * comment and the feedback files — which made a class of thirty unreadable.
 * The row is now a summary plus a button; the rest is fetched on open, the
 * same way the material edit modal works, so the page does not ship one copy
 * of the form (and one file input) per student.
 */
class GradeModalTest extends TestCase
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

        $this->course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $this->course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        $this->assignment = Material::factory()->create([
            'section_id' => $section->id,
            'type' => Material::TYPE_ASSIGNMENT,
            'is_published' => true,
            'due_date' => now()->addWeek(),
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

    private function submission(int $files = 1, int $feedback = 0): Submission
    {
        $submission = Submission::firstOrCreate(
            ['material_id' => $this->assignment->id, 'user_id' => $this->student->id],
            ['submitted_at' => now(), 'last_modified_at' => now()],
        );

        for ($i = 0; $i < $files; $i++) {
            $submission->files()->create([
                'file_path' => CourseMedia::assignmentFolder(
                    $this->course->id, $this->assignment->id, $this->student->id
                )."/w{$i}.pdf",
                'original_name' => "essay{$i}.pdf",
                'size_bytes' => 10, 'mime_type' => 'application/pdf', 'uploaded_at' => now(),
            ]);
        }

        for ($i = 0; $i < $feedback; $i++) {
            $submission->feedbackFiles()->create([
                'file_path' => CourseMedia::feedbackFolder(
                    $this->course->id, $this->assignment->id, $this->student->id
                )."/f{$i}.pdf",
                'original_name' => "marked{$i}.pdf",
                'size_bytes' => 10, 'mime_type' => 'application/pdf', 'uploaded_at' => now(),
                'uploaded_by_user_id' => $this->teacher->id,
            ]);
        }

        return $submission;
    }

    private function roster(): string
    {
        return $this->actingAs($this->teacher)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->getContent();
    }

    private function modal(Submission $submission): string
    {
        return $this->actingAs($this->teacher)
            ->get(route('submissions.grade-modal', $submission))
            ->assertOk()
            ->getContent();
    }

    /** The row is a summary and a toggle, not a set of controls. */
    public function test_the_row_carries_a_grade_toggle_not_the_forms(): void
    {
        $this->submission();
        $html = $this->roster();

        $this->assertMatchesRegularExpression('/openGradeFor === \d+ \? null : \d+/', $html,
            'The Grade toggle is missing.');
        $this->assertStringNotContainsString('name="comment"', $html,
            'The comment field should be fetched, not shipped with the row.');
        $this->assertStringNotContainsString('name="feedback_files[]"', $html,
            'The feedback upload should be fetched, not shipped with the row.');
    }

    /**
     * The form is fetched, not rendered per student. Thirty inline copies is
     * what this design exists to avoid.
     */
    public function test_the_form_is_not_shipped_with_the_page(): void
    {
        $this->submission();

        $this->assertStringNotContainsString('Save grade', $this->roster());
    }

    /**
     * The row is status and a button. Counts and the last-modified time live
     * in the panel, and the username came off entirely.
     */
    public function test_the_row_is_status_and_a_button_only(): void
    {
        $this->submission(files: 2, feedback: 1);
        $html = $this->roster();

        $this->assertStringContainsString($this->student->name, $html);
        $this->assertStringNotContainsString('2 files', $html);
        $this->assertStringNotContainsString('1 feedback', $html);
        $this->assertStringNotContainsString('('.$this->student->username.')', $html,
            'The username should no longer appear beside the name.');
    }

    /** The button sits at the right end of the row, on the name's line. */
    public function test_the_grade_button_shares_the_name_line(): void
    {
        $this->submission();
        $html = $this->roster();

        $this->assertMatchesRegularExpression(
            '/justify-between.*?'.preg_quote($this->student->name, '/').'.*?openGradeFor === \d+/s',
            $html,
            'The button should sit opposite the name in the same flex row.',
        );
    }

    /**
     * The detail opens inline, pushing the roster down, rather than over it.
     *
     * The overlay version is kept in the view behind a disabled condition; if
     * it is ever brought back, these expectations are what change.
     */
    public function test_the_detail_opens_inline_not_as_an_overlay(): void
    {
        $this->submission();
        $html = $this->roster();

        $this->assertStringNotContainsString('fixed inset-0 z-40', $html,
            'The overlay should be parked, not rendered.');
        // The helper is still defined for the parked overlay; what matters is
        // that nothing binds to it, so no lock is applied.
        $this->assertStringNotContainsString('x-effect="lockScroll(', $html,
            'Nothing to lock when the panel is part of the page.');
        $this->assertStringContainsString('x-data="gradeDetail()"', $html);
    }

    /** One panel per row, each fetching only when it is opened. */
    public function test_each_row_has_its_own_panel_fetched_on_open(): void
    {
        $this->submission();

        $this->assertSame(1, substr_count($this->roster(), 'x-data="gradeDetail()"'));
    }

    public function test_the_panel_carries_everything_that_left_the_row(): void
    {
        $submission = $this->submission(files: 1, feedback: 1);
        $html = $this->modal($submission);

        $this->assertStringContainsString('essay0.pdf', $html);
        $this->assertStringContainsString('name="grade"', $html);
        $this->assertStringContainsString('name="comment"', $html);
        $this->assertStringContainsString('marked0.pdf', $html);
        $this->assertStringContainsString('name="feedback_files[]"', $html);
    }

    /**
     * The panel repeats neither the name nor a close icon: the row it opens
     * under carries the name, and its Grade button toggles to Close.
     */
    public function test_the_panel_does_not_repeat_the_row(): void
    {
        $this->student->update(['name' => 'Alice Tan', 'username' => 'zzz_unique_login']);
        $html = $this->modal($this->submission());

        $this->assertStringNotContainsString('Alice Tan', $html);
        $this->assertStringNotContainsString('zzz_unique_login', $html);
        $this->assertStringNotContainsString('aria-label="Close"', $html,
            'The corner icon is gone; the footer Close remains.');
    }

    /** Feedback is laid out like the student's, as a table under the status. */
    public function test_the_feedback_section_is_a_table_like_the_students(): void
    {
        $submission = $this->submission(files: 1, feedback: 1);
        $submission->update(['grade' => '85', 'graded_at' => now()]);

        $html = $this->modal($submission);

        $this->assertStringContainsString('>Feedback</h2>', $html);
        foreach (['Grade', 'Graded on', 'Comment', 'Feedback files'] as $row) {
            $this->assertStringContainsString('<th scope="row">'.$row.'</th>', $html);
        }
        $this->assertLessThan(
            strpos($html, '>Feedback</h2>'),
            strpos($html, '>Submission Status</h2>'),
            'Feedback belongs under Submission Status.',
        );
    }

    /**
     * The grade form sits outside the table and its controls reach it by
     * form="…".
     *
     * A form element cannot legally wrap table rows, and wrapping the table
     * would nest the feedback upload form inside the grade form — also
     * invalid, and browsers resolve it by dropping one.
     */
    public function test_the_grade_controls_are_bound_to_a_form_outside_the_table(): void
    {
        $submission = $this->submission();
        $html = $this->modal($submission);
        $formId = 'grade-form-'.$submission->id;

        $this->assertStringContainsString('id="'.$formId.'"', $html);
        $this->assertSame(3, substr_count($html, 'form="'.$formId.'"'),
            'The grade input, the comment and the save button must all bind to it.');
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
    }

    /**
     * The panel saves over fetch and stays open, so grading wants an answer
     * rather than a page.
     */
    public function test_an_xhr_grade_save_gets_no_content_instead_of_a_redirect(): void
    {
        $submission = $this->submission();

        $this->actingAs($this->teacher)
            ->patch(route('submissions.grade', $submission), [
                'grade' => '92', 'comment' => 'Well done.',
            ], ['Accept' => 'application/json'])
            ->assertNoContent();

        $submission->refresh();
        $this->assertSame('92', $submission->grade);
        $this->assertSame('Well done.', $submission->comment);
        $this->assertNotNull($submission->graded_at);
    }

    /** A plain form post still redirects, for the no-JavaScript path. */
    public function test_a_plain_grade_post_still_redirects(): void
    {
        $submission = $this->submission();

        $this->actingAs($this->teacher)
            ->patch(route('submissions.grade', $submission), ['grade' => '92'])
            ->assertRedirect();
    }

    /**
     * Every control posts through submitInPlace, so none navigates away: the
     * grade form, the feedback upload and each remove.
     */
    public function test_nothing_in_the_panel_navigates_away(): void
    {
        $submission = $this->submission(files: 1, feedback: 1);
        $html = $this->modal($submission);

        $this->assertSame(3, substr_count($html, 'submitInPlace('),
            'The grade form, the upload and the remove should all post in place.');
        // requestSubmit, not submit: the DOM submit() fires no submit event,
        // so the prevent handler would never run and the page would navigate.
        $this->assertStringContainsString('$root.requestSubmit()', $html);
        $this->assertStringNotContainsString('$root.submit()', $html);
    }

    /**
     * Saving closes the panel, which refreshes the page — that is what brings
     * the row's Graded badge up to date. Attaching a file does not close, so
     * only the grade form passes the flag.
     */
    public function test_only_the_grade_form_closes_on_submit(): void
    {
        $html = $this->modal($this->submission(files: 1, feedback: 1));

        $this->assertStringContainsString('submitInPlace($event.target, true)', $html);
        $this->assertSame(1, substr_count($html, 'submitInPlace($event.target, true)'),
            'Only the grade form should close the panel.');
        $this->assertSame(2, substr_count($html, 'submitInPlace($event.target)'),
            'The upload and the remove should leave it open.');
    }

    /** No confirmation banner: closing and refreshing is the confirmation. */
    public function test_there_is_no_saved_banner(): void
    {
        $this->submission();

        $this->assertStringNotContainsString('notice', $this->roster());
    }

    /**
     * Clearing both fields un-grades the submission.
     *
     * This is the other half of the student-side lock: graded_at is what
     * closes their submission, so emptying the form has to null it or a mark
     * entered by mistake would shut them out permanently.
     */
    public function test_clearing_both_fields_ungrades_the_submission(): void
    {
        $submission = $this->submission();
        $submission->update(['grade' => '85', 'comment' => 'Good.', 'graded_at' => now()]);

        $this->actingAs($this->teacher)
            ->patch(route('submissions.grade', $submission), ['grade' => '', 'comment' => ''])
            ->assertRedirect();

        $submission->refresh();

        $this->assertNull($submission->graded_at, 'Clearing the form should reopen the submission.');
        $this->assertNull($submission->grade);
        $this->assertNull($submission->graded_by_user_id);
        $this->assertFalse($submission->isGraded());
    }

    /** A comment alone still counts as marked. */
    public function test_a_comment_without_a_grade_still_marks_it(): void
    {
        $submission = $this->submission();

        $this->actingAs($this->teacher)
            ->patch(route('submissions.grade', $submission), ['grade' => '', 'comment' => 'See me.'])
            ->assertRedirect();

        $this->assertTrue($submission->refresh()->isGraded());
    }

    /** Close on the left, save on the right. */
    public function test_the_footer_has_close_then_save(): void
    {
        $html = $this->modal($this->submission());

        // The word, not an icon.
        preg_match('/<button type="button"[^>]*>\s*Close\s*<\/button>/s', $html, $m, PREG_OFFSET_CAPTURE);

        $this->assertNotEmpty($m, 'The footer Close button is missing.');
        $this->assertLessThan(strpos($html, 'Save grade'), $m[0][1],
            'Close should sit left of Save grade.');
    }

    /** The comment is a textarea now — there is room for more than a line. */
    public function test_the_comment_is_a_textarea(): void
    {
        $submission = $this->submission();
        $submission->update(['comment' => "Line one.\nLine two."]);

        $html = $this->modal($submission);

        $this->assertStringContainsString('<textarea name="comment"', $html);
        $this->assertStringContainsString('Line two.', $html);
    }

    /** The modal shows the same status table the student sees. */
    public function test_the_modal_carries_the_submission_status_table(): void
    {
        $submission = $this->submission(files: 1);
        $submission->update(['grade' => '85', 'graded_at' => now()]);

        $html = $this->modal($submission);

        $this->assertStringContainsString('Submission Status', $html);
        foreach (['Submission status', 'Grading status', 'Time remaining', 'Last modified', 'File submission'] as $row) {
            $this->assertStringContainsString($row, $html, "Missing the {$row} row.");
        }
        $this->assertStringContainsString('Submitted for grading', $html);
        $this->assertStringContainsString('Graded', $html);
    }

    /**
     * The table is read-only here. A student's work is theirs to add to and
     * remove; the teacher gets the same view without the controls.
     */
    public function test_the_modal_table_offers_no_upload_or_remove(): void
    {
        $html = $this->modal($this->submission(files: 1));

        $this->assertStringNotContainsString('name="files[]"', $html,
            'The student upload form should not appear in the teacher modal.');
        $this->assertStringNotContainsString('Remove a file above', $html);
    }

    /**
     * The table's styles are pushed by the roster page, not the fragment.
     *
     * A fragment fetched into a modal cannot reach the head stack — it was
     * already rendered — so pushing from there leaves the table unstyled with
     * nothing to show for it.
     */
    public function test_the_table_styles_come_from_the_roster_page(): void
    {
        $this->submission();

        $this->assertStringContainsString('.detail-table th', $this->roster(),
            'The roster page must emit the table styles for the modal.');
        $this->assertStringNotContainsString('.detail-table th', $this->modal($this->submission()),
            'The fragment cannot push styles; they would be discarded.');
    }

    /** A student's own work is not the teacher's to delete. */
    public function test_the_modal_does_not_offer_to_remove_the_students_files(): void
    {
        $submission = $this->submission();

        $this->assertStringNotContainsString(
            'action="'.route('submission-files.destroy', $submission->files->first()).'"',
            $this->modal($submission),
            'The modal should link to the file, not offer to delete it.',
        );
    }

    /** No submitted work, no feedback upload — same rule as before the move. */
    public function test_the_modal_offers_no_feedback_upload_without_a_submission(): void
    {
        $submission = Submission::create([
            'material_id' => $this->assignment->id,
            'user_id' => $this->student->id,
            'submitted_at' => now(),
        ]);
        $submission->update(['grade' => '50', 'graded_at' => now()]);

        $html = $this->modal($submission);

        $this->assertStringContainsString('Nothing submitted.', $html);
        $this->assertStringNotContainsString('name="feedback_files[]"', $html);
    }

    public function test_a_student_cannot_open_the_grading_modal(): void
    {
        $submission = $this->submission();

        $this->actingAs($this->student)
            ->get(route('submissions.grade-modal', $submission))
            ->assertForbidden();
    }

    public function test_a_teacher_from_another_course_cannot_open_it(): void
    {
        $submission = $this->submission();

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('teacher');

        $this->actingAs($outsider)
            ->get(route('submissions.grade-modal', $submission))
            ->assertForbidden();
    }

    /** Nothing to grade, no button. */
    public function test_a_student_who_has_not_submitted_gets_no_button(): void
    {
        $html = $this->roster();

        $this->assertStringContainsString('Not submitted', $html);
        // The modal's own close handler assigns null, so only a numeric
        // assignment means a button was rendered for a student.
        $this->assertDoesNotMatchRegularExpression('/openGradeFor = \d+/', $html);
    }
}
