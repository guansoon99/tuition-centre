<?php

namespace Tests\Feature\Teacher;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\Submission;
use App\Models\User;
use App\Support\CourseMedia;
use App\Support\PrivateFile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/**
 * Names in and on the submissions ZIP.
 *
 * Both used to go through Str::slug, which strips non-ASCII outright. In a
 * school where titles and student names are routinely Chinese that was not an
 * edge case: the archive was named after a fallback, and EVERY Chinese-named
 * student landed in a folder called "unknown", separated only by a trailing
 * user id. A teacher unpacking it could not tell whose work was whose.
 */
class SubmissionZipNamingTest extends TestCase
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
            'title' => 'Essay One',
        ]);

        $this->teacher = $this->enrol('Zoe Teacher', Enrollment::ROLE_TEACHER, 'teacher');
    }

    private function enrol(string $name, string $role = Enrollment::ROLE_STUDENT, string $siteRole = 'student'): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->assignRole($siteRole);
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $user->id,
            'role_on_course' => $role, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        return $user;
    }

    private function submitFor(User $student, string $originalName = 'work.pdf'): void
    {
        $path = CourseMedia::assignmentFolder(
            $this->course->id, $this->assignment->id, $student->id
        ).'/'.uniqid().'.pdf';

        Storage::disk(PrivateFile::disk())->put($path, '%PDF-1.4 work');

        Submission::firstOrCreate(
            ['material_id' => $this->assignment->id, 'user_id' => $student->id],
            ['submitted_at' => now()],
        )->files()->create([
            'file_path' => $path,
            'original_name' => $originalName,
            'size_bytes' => 13,
            'mime_type' => 'application/pdf',
            'uploaded_at' => now(),
        ]);
    }

    /** Download the ZIP and return [contentDisposition, [entry names]]. */
    private function downloadZip(): array
    {
        $response = $this->actingAs($this->teacher)
            ->get(route('submissions.download-all', $this->assignment));

        $response->assertOk();

        $disposition = $response->headers->get('content-disposition');

        // BinaryFileResponse keeps the built archive on disk until it is sent.
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $zip->open($path);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->statIndex($i)['name'];
        }
        $zip->close();

        return [$disposition, $entries];
    }

    public function test_the_archive_is_named_after_the_assignment(): void
    {
        $this->submitFor($this->enrol('Alice Tan'));

        [$disposition] = $this->downloadZip();

        $this->assertStringContainsString('Essay One.zip', $disposition);
        $this->assertStringNotContainsString('_submissions', $disposition);
    }

    public function test_a_chinese_assignment_title_survives_into_the_filename(): void
    {
        $this->assignment->update(['title' => '上课资料']);
        $this->submitFor($this->enrol('Alice Tan'));

        [$disposition] = $this->downloadZip();

        $this->assertStringContainsString(rawurlencode('上课资料').'.zip', $disposition);
    }

    /** The folder is the student's real name, not "unknown". */
    public function test_chinese_student_names_become_readable_folders(): void
    {
        $this->submitFor($this->enrol('陈大文'));
        $this->submitFor($this->enrol('林小明'));
        $this->submitFor($this->enrol('Alice Tan'));

        [, $entries] = $this->downloadZip();

        $folders = array_unique(array_map(fn ($e) => explode('/', $e)[0], $entries));
        sort($folders);

        $this->assertEqualsCanonicalizing(['Alice Tan', '陈大文', '林小明'], $folders);
        foreach ($folders as $folder) {
            $this->assertStringNotContainsString('unknown', $folder);
        }
    }

    /**
     * Two students with the same name still need separate folders — the old
     * code disambiguated with the user id and that must not have been lost.
     */
    public function test_students_with_identical_names_get_separate_folders(): void
    {
        $this->submitFor($this->enrol('陈大文'));
        $this->submitFor($this->enrol('陈大文'));

        [, $entries] = $this->downloadZip();

        $folders = array_unique(array_map(fn ($e) => explode('/', $e)[0], $entries));

        $this->assertCount(2, $folders, 'Two students with one name collapsed into a single folder.');
    }

    /** A name with no usable characters still has to go somewhere. */
    public function test_an_unusable_student_name_falls_back_to_unknown(): void
    {
        $this->submitFor($this->enrol('///'));

        [, $entries] = $this->downloadZip();

        $this->assertSame('unknown', explode('/', $entries[0])[0]);
    }

    public function test_the_students_files_are_inside_their_folder(): void
    {
        $this->submitFor($this->enrol('陈大文'), '我的作业.pdf');

        [, $entries] = $this->downloadZip();

        $this->assertSame(['陈大文/我的作业.pdf'], $entries);
    }
}
