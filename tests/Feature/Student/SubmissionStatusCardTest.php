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
use Tests\TestCase;

/**
 * The Submission Status card a student sees above their files.
 *
 * Four facts, in one place: whether the work is in, whether it has been marked,
 * how long is left, and when it was last touched. The last one is the only
 * subtle one — see test_last_modified_follows_the_most_recent_upload.
 */
class SubmissionStatusCardTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Material $assignment;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

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

        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    /** Upload one file through the real route, so the controller records it. */
    private function upload(string $name): void
    {
        Storage::fake(PrivateFile::disk());

        $this->actingAs($this->student)
            ->post(route('submissions.upload', $this->assignment), [
                'files' => [UploadedFile::fake()->create($name, 10, 'application/pdf')],
            ])
            ->assertSessionHasNoErrors();
    }

    /** A submission whose files were uploaded at the given times. */
    private function submitAt(array $times): Submission
    {
        $submission = Submission::firstOrCreate(
            ['material_id' => $this->assignment->id, 'user_id' => $this->student->id],
            ['submitted_at' => $times[0] ?? now()],
        );

        foreach ($times as $i => $at) {
            $submission->files()->create([
                'file_path' => CourseMedia::assignmentFolder(
                    $this->course->id, $this->assignment->id, $this->student->id
                )."/f{$i}.pdf",
                'original_name' => "f{$i}.pdf",
                'size_bytes' => 10,
                'mime_type' => 'application/pdf',
                'uploaded_at' => $at,
            ]);
        }

        return $submission;
    }

    /**
     * Just the status card. Scoped, because "Submissions closed" and the file
     * timestamps also appear elsewhere on the page.
     */
    private function card(): string
    {
        $html = $this->actingAs($this->student)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->getContent();

        preg_match('/Submission Status<\/h2>(.*?)<\/table>/s', $html, $m);

        $this->assertNotEmpty($m, 'Could not find the Submission Status card.');

        return $m[1];
    }

    /**
     * One row's value.
     *
     * The card carries the file list now, so a card-wide search for a
     * timestamp finds the files' own timestamps and proves nothing about the
     * Last modified row.
     */
    private function row(string $label): string
    {
        preg_match('/'.preg_quote($label, '/').'<\/th>\s*<td[^>]*>(.*?)<\/td>/s', $this->card(), $m);

        $this->assertNotEmpty($m, "Could not find the {$label} row.");

        return $m[1];
    }

    /** The files live inside the card now, as its last row. */
    public function test_the_files_are_the_last_row_of_the_card(): void
    {
        $card = $this->card();

        $this->assertStringContainsString('File submission', $card);
        $this->assertStringContainsString('name="files[]"', $card,
            'The upload form should sit inside the status card.');

        foreach (['Submission status', 'Grading status', 'Time remaining', 'Last modified'] as $earlier) {
            $this->assertLessThan(
                strpos($card, 'File submission'),
                strpos($card, $earlier),
                "{$earlier} should come before File submission.",
            );
        }
    }

    /** The heading is a bare title above the card, not inside it. */
    public function test_the_heading_sits_outside_the_card(): void
    {
        $html = $this->actingAs($this->student)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->getContent();

        // Blade comments are stripped from the output, so the heading and the
        // card are adjacent with only whitespace between them.
        $this->assertMatchesRegularExpression(
            '/<h2 class="text-xl font-semibold text-slate-900">Submission Status<\/h2>\s*<div class="overflow-hidden rounded-lg/s',
            $html,
            'The heading should be outside the bordered card, like the page title.',
        );
    }

    public function test_it_lists_all_four_rows(): void
    {
        $card = $this->card();

        foreach (['Submission status', 'Grading status', 'Time remaining', 'Last modified'] as $label) {
            $this->assertStringContainsString($label, $card, "Missing the {$label} row.");
        }
    }

    public function test_a_submitted_assignment_reads_submitted_for_grading(): void
    {
        $this->submitAt([now()]);

        $this->assertStringContainsString('Submitted for grading', $this->card());
    }

    public function test_nothing_handed_in_says_so(): void
    {
        $card = $this->card();

        $this->assertStringContainsString('No submissions have been made yet', $card);
        $this->assertStringNotContainsString('Submitted for grading', $card);
    }

    /**
     * A submission row with no files is an abandoned upload. The teacher's list
     * and the status export both treat that as not submitted, and the student
     * has to be told the same thing — otherwise they believe they are done.
     */
    public function test_a_submission_with_no_files_is_not_a_submission(): void
    {
        Submission::create([
            'material_id' => $this->assignment->id,
            'user_id' => $this->student->id,
            'submitted_at' => now(),
        ]);

        $this->assertStringContainsString('No submissions have been made yet', $this->card());
    }

    public function test_grading_status_reads_not_graded_until_marked(): void
    {
        $this->submitAt([now()]);

        $this->assertStringContainsString('Not Graded', $this->card());
    }

    public function test_grading_status_reads_graded_once_marked(): void
    {
        $this->submitAt([now()])->update(['grade' => '35', 'graded_at' => now()]);

        $card = $this->card();

        $this->assertStringContainsString('Graded', $card);
        $this->assertStringNotContainsString('Not Graded', $card);
    }

    public function test_time_remaining_counts_down_while_the_assignment_is_open(): void
    {
        $this->assertStringContainsString('setInterval(() => tick(), 30000)', $this->card());
    }

    public function test_time_remaining_reads_closed_once_past_due(): void
    {
        $this->assignment->update(['due_date' => now()->subDay()]);

        $card = $this->card();

        $this->assertStringContainsString('Submissions closed', $card);
        $this->assertStringNotContainsString('tick()', $card, 'Nothing left to count down.');
    }

    public function test_time_remaining_handles_an_assignment_with_no_deadline(): void
    {
        $this->assignment->update(['due_date' => null]);

        $card = $this->card();

        $this->assertStringContainsString('No due date', $card);
        $this->assertStringNotContainsString('tick()', $card);
    }

    /** Nothing handed in yet, so there is no date to show. */
    public function test_last_modified_is_a_dash_before_the_first_upload(): void
    {
        $row = $this->row('Last modified');

        $this->assertStringContainsString('—', $row);
        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $row);
    }

    /** An abandoned submission row is not an upload either. */
    public function test_last_modified_is_a_dash_for_a_submission_with_no_files(): void
    {
        Submission::create([
            'material_id' => $this->assignment->id,
            'user_id' => $this->student->id,
            'submitted_at' => now(),
        ]);

        $this->assertStringContainsString('—', $this->row('Last modified'));
    }

    /** It starts at the first upload, recorded through the real route. */
    public function test_the_first_upload_starts_the_clock(): void
    {
        $this->travelTo(now()->startOfMinute());

        $this->upload('essay.pdf');

        $this->assertStringContainsString(now()->format('Y-m-d H:i'), $this->row('Last modified'));
    }

    public function test_a_later_upload_moves_it(): void
    {
        $this->travelTo(now()->subDays(3)->startOfMinute());
        $this->upload('first.pdf');
        $first = now();

        $this->travelTo(now()->addDays(3)->startOfMinute());
        $this->upload('second.pdf');

        $row = $this->row('Last modified');

        $this->assertStringContainsString(now()->format('Y-m-d H:i'), $row);
        $this->assertStringNotContainsString($first->format('Y-m-d H:i'), $row,
            'Last modified is still reporting the first upload.');
    }

    /**
     * The case that motivated the column: removing a file is a modification.
     *
     * Derived from the files, this went blank the moment the last one was
     * deleted — the record of the change vanished with the thing that changed.
     * It has to survive an empty submission.
     */
    public function test_removing_every_file_keeps_the_last_modified_time(): void
    {
        $this->travelTo(now()->subDay()->startOfMinute());
        $this->upload('essay.pdf');
        $uploadedAt = now();

        $this->travelTo(now()->addDay()->startOfMinute());
        $this->actingAs($this->student)
            ->delete(route('submission-files.destroy', SubmissionFile::firstOrFail()))
            ->assertRedirect();

        $this->assertSame(0, SubmissionFile::count(), 'The file should be gone.');

        $row = $this->row('Last modified');

        $this->assertStringContainsString(now()->format('Y-m-d H:i'), $row,
            'Removing a file is a modification and should be recorded as one.');
        $this->assertStringNotContainsString($uploadedAt->format('Y-m-d H:i'), $row);
        // And the status returns to "not submitted", since nothing is in.
        $this->assertStringContainsString('No submissions have been made yet', $this->card());
    }

    /** Grading is the teacher touching the row, not the student's own work. */
    public function test_grading_does_not_count_as_a_modification(): void
    {
        $this->travelTo(now()->subDays(2)->startOfMinute());
        $this->upload('essay.pdf');
        $uploadedAt = now();

        $this->travelTo(now()->addDays(2));
        Submission::firstOrFail()->update(['grade' => '80', 'graded_at' => now()]);

        $this->assertStringContainsString($uploadedAt->format('Y-m-d H:i'), $this->row('Last modified'));
    }
}
