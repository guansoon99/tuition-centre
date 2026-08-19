<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\Submission;
use App\Models\User;
use App\Support\CourseMedia;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Feedback section under Submission Status.
 *
 * It replaced a green callout box, and everything that box carried had to come
 * with it — the grade, when it was marked, and the teacher's comment. A comment
 * quietly dropped in the move would be the worst outcome here: the student
 * would never know it had been written.
 */
class FeedbackSectionTest extends TestCase
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

    private function submission(): Submission
    {
        $submission = Submission::firstOrCreate(
            ['material_id' => $this->assignment->id, 'user_id' => $this->student->id],
            ['submitted_at' => now(), 'last_modified_at' => now()],
        );

        $submission->files()->firstOrCreate([
            'file_path' => CourseMedia::assignmentFolder(
                $this->course->id, $this->assignment->id, $this->student->id
            ).'/work.pdf',
            'original_name' => 'work.pdf',
            'size_bytes' => 10,
            'mime_type' => 'application/pdf',
            'uploaded_at' => now(),
        ]);

        return $submission;
    }

    private function grade(array $attributes = []): Submission
    {
        $submission = $this->submission();
        $submission->update($attributes + ['grade' => '85', 'graded_at' => now()]);

        return $submission;
    }

    private function page(): string
    {
        return $this->actingAs($this->student)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->getContent();
    }

    /** Just the Feedback table, so Submission Status cannot satisfy an assertion. */
    private function section(): string
    {
        preg_match('/Feedback<\/h2>(.*?)<\/table>/s', $this->page(), $m);

        return $m[1] ?? '';
    }

    public function test_it_shows_the_grade_and_when_it_was_marked(): void
    {
        $graded = $this->grade();
        $section = $this->section();

        $this->assertNotSame('', $section, 'The Feedback section is missing.');
        $this->assertStringContainsString('Grade', $section);
        $this->assertStringContainsString('85', $section);
        $this->assertStringContainsString('Graded on', $section);
        $this->assertStringContainsString($graded->graded_at->format('Y-m-d H:i'), $section);
    }

    /** The comment lived in the green box; it must not have been lost with it. */
    public function test_it_shows_the_teachers_comment(): void
    {
        $this->grade(['comment' => "Good work.\nWatch your units."]);

        $section = $this->section();

        $this->assertStringContainsString('Comment', $section);
        $this->assertStringContainsString('Good work.', $section);
        $this->assertStringContainsString('Watch your units.', $section);
        $this->assertStringContainsString('whitespace-pre-wrap', $section,
            'A multi-line comment collapses into one line without pre-wrap.');
    }

    /**
     * The Comment row is always present, so the section is a fixed set of
     * fields — an empty one reads as "nothing was written", not as a row that
     * failed to render.
     */
    public function test_the_comment_row_is_a_dash_when_there_is_none(): void
    {
        $this->grade();
        $section = $this->section();

        $this->assertStringContainsString('Comment', $section);
        $this->assertStringContainsString('—', $section);
    }

    /** With nothing returned yet the row is present but empty. */
    public function test_the_feedback_files_row_is_a_dash_when_none_were_returned(): void
    {
        $this->grade(['comment' => 'Nice.']);

        preg_match('/Feedback files<\/th>\s*<td[^>]*>(.*?)<\/td>/s', $this->section(), $m);

        $this->assertNotEmpty($m, 'The Feedback files row is missing.');
        $this->assertSame('—', trim($m[1]));
    }

    public function test_the_feedback_section_has_four_rows(): void
    {
        $this->grade();

        $this->assertSame(4, substr_count($this->section(), '<th scope="row">'));
    }

    public function test_there_is_no_feedback_section_before_marking(): void
    {
        $this->submission();

        $this->assertStringNotContainsString('Feedback', $this->page());
    }

    /**
     * A teacher often returns an annotated copy before deciding a mark, so the
     * section cannot key on the grade alone — that hid the file completely.
     */
    public function test_a_returned_file_shows_the_section_even_unmarked(): void
    {
        $submission = $this->submission();
        $submission->feedbackFiles()->create([
            'file_path' => 'course-media/1/feedback/1/1/x.pdf',
            'original_name' => 'marked.pdf',
            'size_bytes' => 10,
            'mime_type' => 'application/pdf',
            'uploaded_at' => now(),
        ]);

        $section = $this->section();

        $this->assertStringContainsString('marked.pdf', $section);
        // No mark yet, so the graded fields read as empty rather than crashing
        // on a null date.
        $this->assertStringContainsString('—', $section);
    }

    public function test_there_is_no_feedback_section_without_a_submission(): void
    {
        $this->assertStringNotContainsString('Feedback', $this->page());
    }

    /** The green callout is gone; this is a table like the one above it. */
    public function test_the_green_callout_is_gone(): void
    {
        $this->grade(['comment' => 'Nice.']);
        $html = $this->page();

        $this->assertStringNotContainsString('bg-emerald-50', $html);
        $this->assertStringNotContainsString('border-emerald-200', $html);
        $this->assertStringNotContainsString('text-emerald-900', $html);
    }

    /** Same table styling as Submission Status, and directly under it. */
    public function test_it_matches_the_status_table_and_sits_below_it(): void
    {
        $this->grade();
        $html = $this->page();

        $this->assertSame(2, substr_count($html, 'class="detail-table"'),
            'Both sections should use the shared table style.');
        $this->assertLessThan(
            strpos($html, 'Feedback</h2>'),
            strpos($html, 'Submission Status</h2>'),
            'Feedback should come after Submission Status.',
        );
    }

    /** The heading is a bare title above the card, like Submission Status. */
    public function test_the_heading_sits_outside_the_card(): void
    {
        $this->grade();

        $this->assertMatchesRegularExpression(
            '/<h2 class="text-xl font-semibold text-slate-900">Feedback<\/h2>\s*<div class="overflow-hidden rounded-lg/s',
            $this->page(),
        );
    }
}
