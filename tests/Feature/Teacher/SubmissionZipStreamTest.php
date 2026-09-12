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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * "Download all" streams the ZIP instead of building it on disk first, so a
 * large class never buffers every file in memory and the download starts at
 * once. This checks the download is a stream, its contents are correct, a
 * file gone missing is skipped rather than fatal, and access is still gated.
 */
class SubmissionZipStreamTest extends TestCase
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

    private function submitFor(User $student, string $originalName, string $body): string
    {
        $path = CourseMedia::assignmentFolder(
            $this->course->id, $this->assignment->id, $student->id
        ).'/'.uniqid().'.pdf';

        Storage::disk(PrivateFile::disk())->put($path, $body);

        Submission::firstOrCreate(
            ['material_id' => $this->assignment->id, 'user_id' => $student->id],
            ['submitted_at' => now()],
        )->files()->create([
            'file_path' => $path,
            'original_name' => $originalName,
            'size_bytes' => strlen($body),
            'mime_type' => 'application/pdf',
            'uploaded_at' => now(),
        ]);

        return $path;
    }

    /** @return array<string,string> entry name => contents */
    private function downloadEntries(): array
    {
        $response = $this->actingAs($this->teacher)
            ->get(route('submissions.download-all', $this->assignment));

        $response->assertOk();
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        $this->assertSame('application/zip', $response->headers->get('content-type'));

        $tmp = tempnam(sys_get_temp_dir(), 'zipstream_').'.zip';
        file_put_contents($tmp, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true, 'The streamed bytes are a valid ZIP.');
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->statIndex($i)['name'];
            $entries[$name] = $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        return $entries;
    }

    public function test_the_download_is_a_stream_not_a_built_file(): void
    {
        $this->submitFor($this->enrol('Alice Tan'), 'work.pdf', '%PDF-1.4 alice');

        $response = $this->actingAs($this->teacher)
            ->get(route('submissions.download-all', $this->assignment));

        $response->assertOk();
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        $this->assertSame('no', $response->headers->get('x-accel-buffering'));
    }

    public function test_every_submitted_file_is_in_the_zip_with_its_bytes(): void
    {
        $this->submitFor($this->enrol('Alice Tan'), 'essay.pdf', '%PDF alice bytes');
        $this->submitFor($this->enrol('Bob Lee'), 'essay.pdf', '%PDF bob bytes');

        $entries = $this->downloadEntries();

        $this->assertArrayHasKey('Alice Tan/essay.pdf', $entries);
        $this->assertArrayHasKey('Bob Lee/essay.pdf', $entries);
        $this->assertSame('%PDF alice bytes', $entries['Alice Tan/essay.pdf']);
        $this->assertSame('%PDF bob bytes', $entries['Bob Lee/essay.pdf']);
    }

    public function test_the_total_size_is_announced_up_front_and_is_exact(): void
    {
        // What turns the browser's "12 MB so far" into a percentage: the
        // archive's length is declared before the first byte, and it has to
        // match the bytes that follow or the download is truncated/corrupt.
        $this->submitFor($this->enrol('Alice Tan'), 'a.pdf', str_repeat('A', 3000));
        $this->submitFor($this->enrol('Bob Lee'), 'b.pdf', str_repeat('B', 12345));

        $response = $this->actingAs($this->teacher)
            ->get(route('submissions.download-all', $this->assignment));

        $announced = (int) $response->headers->get('content-length');
        $actual = strlen($response->streamedContent());

        $this->assertGreaterThan(15345, $announced, 'Headers and directory come on top of the file bytes.');
        $this->assertSame($announced, $actual);
    }

    public function test_files_are_stored_not_compressed(): void
    {
        $this->submitFor($this->enrol('Alice Tan'), 'work.pdf', str_repeat('A', 500));

        $response = $this->actingAs($this->teacher)
            ->get(route('submissions.download-all', $this->assignment));
        $tmp = tempnam(sys_get_temp_dir(), 'zipstream_').'.zip';
        file_put_contents($tmp, $response->streamedContent());

        $zip = new ZipArchive;
        $zip->open($tmp);
        $stat = $zip->statIndex(0);
        $zip->close();
        @unlink($tmp);

        // STORE (0) means the compressed and uncompressed sizes match.
        $this->assertSame(ZipArchive::CM_STORE, $stat['comp_method']);
        $this->assertSame($stat['size'], $stat['comp_size']);
    }

    public function test_a_file_gone_missing_is_skipped_not_fatal(): void
    {
        $alice = $this->enrol('Alice Tan');
        $this->submitFor($alice, 'here.pdf', '%PDF here');
        $gonePath = $this->submitFor($alice, 'gone.pdf', '%PDF gone');

        // The row still points at it, but the object is gone from storage.
        Storage::disk(PrivateFile::disk())->delete($gonePath);

        $entries = $this->downloadEntries();

        $this->assertArrayHasKey('Alice Tan/here.pdf', $entries);
        $this->assertArrayNotHasKey('Alice Tan/gone.pdf', $entries);
    }

    public function test_an_empty_assignment_still_refuses(): void
    {
        $this->actingAs($this->teacher)
            ->get(route('submissions.download-all', $this->assignment))
            ->assertRedirect();
    }

    public function test_a_teacher_off_the_course_cannot_download(): void
    {
        $this->submitFor($this->enrol('Alice Tan'), 'work.pdf', '%PDF alice');

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('teacher');

        $this->actingAs($outsider)
            ->get(route('submissions.download-all', $this->assignment))
            ->assertForbidden();
    }
}
