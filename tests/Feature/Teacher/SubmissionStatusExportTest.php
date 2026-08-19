<?php

namespace Tests\Feature\Teacher;

use App\Exports\SubmissionStatusExport;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\Submission;
use App\Models\User;
use App\Support\CourseMedia;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The "Status" download: the roster with who has handed in.
 *
 * The one thing worth getting right is that this agrees with the page it is
 * downloaded from. The grading list treats a submission row with no files as
 * NOT submitted — students open the upload form and abandon it, which creates
 * the row — so an export keying off "has a submission row" would mark those
 * students as done and they would never be chased.
 */
class SubmissionStatusExportTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Material $assignment;

    private User $teacher;

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

        $this->teacher = $this->enrol('Zoe Teacher', 'teach01', Enrollment::ROLE_TEACHER, 'teacher');
    }

    private function enrol(
        string $name,
        string $username,
        string $roleOnCourse = Enrollment::ROLE_STUDENT,
        string $role = 'student',
        bool $active = true,
    ): User {
        $user = User::factory()->create([
            'name' => $name, 'username' => $username, 'is_active' => true,
        ]);
        $user->assignRole($role);
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $user->id,
            'role_on_course' => $roleOnCourse, 'is_active' => $active, 'enrolled_at' => now(),
        ]);

        return $user;
    }

    private function submitFor(User $student, bool $withFile = true): Submission
    {
        $submission = Submission::create([
            'material_id' => $this->assignment->id,
            'user_id' => $student->id,
            'submitted_at' => now(),
        ]);

        if ($withFile) {
            $submission->files()->create([
                'file_path' => CourseMedia::assignmentFolder(
                    $this->course->id, $this->assignment->id, $student->id
                ).'/work.pdf',
                'original_name' => 'work.pdf',
                'size_bytes' => 10,
                'mime_type' => 'application/pdf',
                'uploaded_at' => now(),
            ]);
        }

        return $submission;
    }

    /** The sheet as [username, name, status] rows, headings excluded. */
    private function rows(): array
    {
        $export = new SubmissionStatusExport($this->assignment);

        return $export->collection()->map(fn ($s) => $export->map($s))->all();
    }

    public function test_it_lists_username_name_and_status(): void
    {
        $submitted = $this->enrol('Alice Tan', 'student1');
        $this->enrol('Bob Lim', 'student2');
        $this->submitFor($submitted);

        $this->assertSame([
            ['student1', 'Alice Tan', 'Submitted'],
            ['student2', 'Bob Lim', 'Not submitted'],
        ], $this->rows());
    }

    public function test_the_headings_are_username_name_status(): void
    {
        $this->assertSame(
            ['Username', 'Name', 'Status'],
            (new SubmissionStatusExport($this->assignment))->headings(),
        );
    }

    /**
     * The case that makes this more than a join: an abandoned upload leaves a
     * submission row with no files, and the grading page counts that student
     * as not submitted.
     */
    public function test_a_submission_with_no_files_counts_as_not_submitted(): void
    {
        $student = $this->enrol('Carol Ng', 'student3');
        $this->submitFor($student, withFile: false);

        $this->assertSame([['student3', 'Carol Ng', 'Not submitted']], $this->rows());
    }

    public function test_rows_are_ordered_by_name(): void
    {
        $this->enrol('Zara Wong', 'student9');
        $this->enrol('Adam Chan', 'student1');
        $this->enrol('Mei Ling', 'student5');

        $this->assertSame(
            ['Adam Chan', 'Mei Ling', 'Zara Wong'],
            array_column($this->rows(), 1),
        );
    }

    public function test_teachers_and_inactive_enrolments_are_excluded(): void
    {
        $this->enrol('Alice Tan', 'student1');
        $this->enrol('Dropped Student', 'student8', Enrollment::ROLE_STUDENT, 'student', active: false);

        // The teacher from setUp is enrolled on this course too.
        $this->assertSame([['student1', 'Alice Tan', 'Not submitted']], $this->rows());
    }

    public function test_the_route_downloads_a_spreadsheet(): void
    {
        Excel::fake();
        $this->assignment->update(['title' => 'Essay One']);
        $this->enrol('Alice Tan', 'student1');

        $this->actingAs($this->teacher)
            ->get(route('submissions.download-status', $this->assignment))
            ->assertOk();

        Excel::assertDownloaded(
            'Essay One.xlsx',
            fn (SubmissionStatusExport $export) => $export->collection()->count() === 1,
        );
    }

    /**
     * Titles here are routinely Chinese. Str::slug strips non-ASCII outright,
     * so slugifying named the file after the fallback instead of the
     * assignment — the reason the title is now used as-is.
     */
    public function test_a_chinese_title_is_kept_verbatim(): void
    {
        Excel::fake();
        $this->assignment->update(['title' => '上课资料']);

        $this->actingAs($this->teacher)
            ->get(route('submissions.download-status', $this->assignment))
            ->assertOk();

        Excel::assertDownloaded('上课资料.xlsx');
    }

    public function test_spaces_and_punctuation_in_a_title_are_kept(): void
    {
        Excel::fake();
        $this->assignment->update(['title' => 'Week 1 — Essay (draft)']);

        $this->actingAs($this->teacher)
            ->get(route('submissions.download-status', $this->assignment))
            ->assertOk();

        Excel::assertDownloaded('Week 1 — Essay (draft).xlsx');
    }

    /**
     * A title is teacher-entered text, so it reaches a filename and a
     * Content-Disposition header. Path separators must not survive, and
     * neither must the characters Windows refuses to save.
     */
    public static function unsafeTitles(): array
    {
        return [
            // basename() keeps only the last segment, so a path collapses to
            // its leaf rather than being flattened into one long name.
            'path traversal'   => ['../../etc/passwd', 'passwd.xlsx'],
            'windows path'     => ['C:\\Windows\\System32', 'System32.xlsx'],
            'trailing dot'     => ['Essay.', 'Essay.xlsx'],
            'colon'            => ['Homework: Week 1', 'Homework Week 1.xlsx'],
            'quotes'           => ['The "Big" Essay', 'The Big Essay.xlsx'],
            'wildcards'        => ['Report *?<>|', 'Report.xlsx'],
            'empty title'      => ['', 'assignment.xlsx'],
            'only separators'  => ['///', 'assignment.xlsx'],
            'only spaces'      => ['   ', 'assignment.xlsx'],
        ];
    }

    #[DataProvider('unsafeTitles')]
    public function test_unsafe_titles_are_sanitised(string $title, string $expected): void
    {
        Excel::fake();
        $this->assignment->update(['title' => $title]);

        $this->actingAs($this->teacher)
            ->get(route('submissions.download-status', $this->assignment))
            ->assertOk();

        Excel::assertDownloaded($expected);
    }

    /**
     * Not faked: Excel::fake() never builds a response, so it cannot show
     * whether a Chinese filename survives Content-Disposition. Symfony refuses
     * a non-ASCII filename without an ASCII fallback, and Str::ascii() returns
     * nothing for CJK — so this is the assertion that the download actually
     * works, rather than that we asked for it.
     */
    public function test_a_chinese_filename_produces_a_valid_download_response(): void
    {
        $this->assignment->update(['title' => '上课资料']);
        $this->enrol('Alice Tan', 'student1');

        $response = $this->actingAs($this->teacher)
            ->get(route('submissions.download-status', $this->assignment));

        $response->assertOk();

        $disposition = $response->headers->get('content-disposition');

        $this->assertStringContainsString('attachment', $disposition);
        // RFC 5987: the real name rides in filename*, UTF-8 percent-encoded.
        $this->assertStringContainsString("filename*=utf-8''", strtolower($disposition));
        $this->assertStringContainsString(rawurlencode('上课资料'), $disposition);
    }

    /**
     * Unlike the files ZIP, this must work on an assignment nobody has touched
     * — that is the chasing list, and it is when a teacher most wants it.
     */
    public function test_it_works_when_nothing_has_been_submitted(): void
    {
        Excel::fake();
        $this->enrol('Alice Tan', 'student1');
        $this->enrol('Bob Lim', 'student2');

        $this->actingAs($this->teacher)
            ->get(route('submissions.download-status', $this->assignment))
            ->assertOk();

        $this->assertSame(
            ['Not submitted', 'Not submitted'],
            array_column($this->rows(), 2),
        );
    }

    public function test_a_student_cannot_download_the_status_list(): void
    {
        $student = $this->enrol('Alice Tan', 'student1');

        $this->actingAs($student)
            ->get(route('submissions.download-status', $this->assignment))
            ->assertForbidden();
    }

    public function test_a_teacher_from_another_course_cannot_download_it(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('teacher');

        $this->actingAs($outsider)
            ->get(route('submissions.download-status', $this->assignment))
            ->assertForbidden();
    }

    public function test_it_refuses_a_material_that_is_not_an_assignment(): void
    {
        $notAnAssignment = Material::factory()->create([
            'section_id' => $this->assignment->section_id,
            'type' => Material::TYPE_PDF,
        ]);

        $this->actingAs($this->teacher)
            ->get(route('submissions.download-status', $notAnAssignment))
            ->assertNotFound();
    }

    public function test_the_page_offers_both_downloads(): void
    {
        $student = $this->enrol('Alice Tan', 'student1');
        $this->submitFor($student);

        $this->actingAs($this->teacher)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->assertSee(route('submissions.download-all', $this->assignment), false)
            ->assertSee(route('submissions.download-status', $this->assignment), false);
    }

    /** With nothing submitted the ZIP is not offered, but the status list still is. */
    public function test_the_files_option_is_not_offered_when_nothing_is_submitted(): void
    {
        $this->enrol('Alice Tan', 'student1');

        $this->actingAs($this->teacher)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->assertDontSee(route('submissions.download-all', $this->assignment), false)
            ->assertSee(route('submissions.download-status', $this->assignment), false)
            ->assertSee('Nothing submitted yet');
    }
}
