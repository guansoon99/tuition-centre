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
 * The header line on each submission row in the grading list.
 *
 * Status only. The file counts and the last-modified time used to sit here and
 * both moved into the grading modal — its File submission and Last modified
 * rows — leaving the roster as one line of state per student.
 */
class SubmissionRowHeaderTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Material $assignment;

    private User $student;

    private Course $course;

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

    /**
     * A submission opened at $first with a file for each timestamp given.
     * submitted_at deliberately stays at $first so the two can be told apart.
     */
    private function submissionWithUploadsAt(array $times): Submission
    {
        $submission = Submission::create([
            'material_id' => $this->assignment->id,
            'user_id' => $this->student->id,
            'submitted_at' => $times[0],
        ]);

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

    /** Just the badge line, so assertions cannot be satisfied by the file list below it. */
    private function headerLine(): string
    {
        $html = $this->actingAs($this->teacher)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->getContent();

        preg_match('/<div class="mt-1 flex flex-wrap items-center gap-2 text-xs">(.*?)<\/div>/s', $html, $m);

        $this->assertNotEmpty($m, 'Could not find the submission header line.');

        return $m[1];
    }

    /** Order: what was handed in, then whether it has been marked. */
    public function test_the_graded_badge_follows_the_submission_badge(): void
    {
        $submission = $this->submissionWithUploadsAt([now()->subDay()]);
        // isGraded() keys off graded_at, not the grade string.
        $submission->update(['grade' => '35', 'graded_at' => now()]);

        $header = $this->headerLine();

        // Keyed on the badge's own colour, not its text: "Not Graded" contains
        // "Graded", so a text search finds the wrong one.
        $this->assertLessThan(
            strpos($header, 'bg-sky-100'),
            strpos($header, 'Submitted for grading'),
            'The submission badge should come first.',
        );
    }

    /**
     * Counts and timestamps moved to the modal, where there is room for them.
     */
    public function test_the_row_carries_no_counts_or_timestamps(): void
    {
        $this->submissionWithUploadsAt([now()->subDays(3), now()->subDay()]);

        $header = $this->headerLine();

        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $header,
            'The row should carry no timestamp.');
        $this->assertStringNotContainsString('files', $header, 'The file count moved to the modal.');
        $this->assertStringNotContainsString('feedback', $header);
    }

    /** An unmarked row says so, using the student's wording. */
    public function test_an_ungraded_row_says_not_graded(): void
    {
        $this->submissionWithUploadsAt([now()->subDays(7), now()->subDay()]);

        $header = $this->headerLine();

        $this->assertStringContainsString('Not Graded', $header);
        $this->assertStringNotContainsString('bg-sky-100', $header, 'Not marked, so no graded badge.');
    }

    /** The two sides describe one submission the same way. */
    public function test_the_badges_match_the_students_wording(): void
    {
        $this->submissionWithUploadsAt([now()->subDay()]);

        $header = $this->headerLine();

        $this->assertStringContainsString('Submitted for grading', $header);
        $this->assertStringNotContainsString('>\n                                            Submitted\n', $header,
            'The bare "Submitted" wording should be gone.');
    }

    /** A student with no files shows no time at all, rather than a stray blank. */
    public function test_a_student_who_has_not_submitted_shows_no_time(): void
    {
        $header = $this->headerLine();

        $this->assertStringContainsString('Not submitted', $header);
        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $header,
            'A row with no files should carry no timestamp.');
    }
}
