<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Support\PrivateFile;
use App\Support\PublicFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Student assignment uploads.
 *
 * Two paths exist. In production the browser PUTs straight to R2 (presign,
 * then register). Everywhere the disk cannot presign — dev, these tests — and
 * whenever the direct PUT fails, the bytes are proxied through PHP instead.
 * Both are real production paths: a school network that blocks the R2 endpoint
 * falls back to the proxied one.
 *
 * These cover the proxied path end to end, plus register() in full. register()
 * is where the guarantees for direct uploads actually live — the object is
 * already in the bucket by then, so it is the only thing standing between a
 * student and an unchecked file. It needs no presigning to test, because it
 * only ever inspects an object that already exists.
 *
 * presign() is covered for its rejections; its success path calls out to S3
 * and cannot run against a faked disk.
 *
 * The proxied path deliberately writes files BEFORE opening its transaction —
 * storing them inside it would hold a database write lock across the upload
 * (an R2 round-trip in production), which on SQLite blocks every other write
 * in the app. Measured at 6ms -> 2600ms for concurrent page views.
 */
class SubmissionUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Material $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        Storage::fake(PrivateFile::disk());
        Storage::fake(PublicFile::disk());

        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        $this->assignment = Material::factory()->create([
            'section_id' => $section->id,
            'type' => Material::TYPE_ASSIGNMENT,
            'is_published' => true,
            'due_date' => now()->addWeek(),
            'max_files' => 3,
            'max_file_size_mb' => 50,
        ]);

        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');
        Enrollment::create([
            'course_id' => $course->id, 'user_id' => $this->student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    private function upload(array $files)
    {
        return $this->actingAs($this->student)
            ->post(route('submissions.upload', $this->assignment), ['files' => $files]);
    }

    public function test_a_student_can_upload_files(): void
    {
        $this->upload([
            UploadedFile::fake()->create('essay.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->image('photo.jpg'),
        ])->assertRedirect();

        $submission = Submission::where('user_id', $this->student->id)->firstOrFail();
        $this->assertSame(2, $submission->files()->count());

        foreach ($submission->files as $file) {
            Storage::disk(PrivateFile::disk())->assertExists($file->file_path);
        }
    }

    public function test_original_filename_and_metadata_are_recorded(): void
    {
        $this->upload([UploadedFile::fake()->create('My Homework.pdf', 12, 'application/pdf')]);

        $file = SubmissionFile::firstOrFail();
        $this->assertSame('My Homework.pdf', $file->original_name);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertGreaterThan(0, $file->size_bytes);

        // Stored under a UUID, not the user-supplied name.
        $this->assertStringNotContainsString('My Homework', $file->file_path);
    }

    public function test_files_are_stored_privately_not_on_the_public_disk(): void
    {
        $this->upload([UploadedFile::fake()->image('answer.png')]);

        $path = SubmissionFile::firstOrFail()->file_path;
        Storage::disk(PrivateFile::disk())->assertExists($path);
        Storage::disk(PublicFile::disk())->assertMissing($path);
    }

    public function test_uploading_twice_appends_rather_than_replacing(): void
    {
        $this->upload([UploadedFile::fake()->create('one.pdf', 10, 'application/pdf')]);
        $this->upload([UploadedFile::fake()->create('two.pdf', 10, 'application/pdf')]);

        $this->assertSame(1, Submission::count(), 'Should reuse the same submission row.');
        $this->assertSame(2, SubmissionFile::count());
    }

    public function test_the_max_files_cap_is_enforced(): void
    {
        $this->upload([
            UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'),
            UploadedFile::fake()->create('b.pdf', 5, 'application/pdf'),
        ]);

        // max_files is 3; two more would make four.
        $this->upload([
            UploadedFile::fake()->create('c.pdf', 5, 'application/pdf'),
            UploadedFile::fake()->create('d.pdf', 5, 'application/pdf'),
        ])->assertSessionHasErrors('files');

        $this->assertSame(2, SubmissionFile::count());
    }

    /**
     * Files are written before the rows, so a failed insert has to clean them
     * up — otherwise every failure leaks an orphan into storage.
     */
    public function test_no_orphan_files_are_left_when_the_insert_fails(): void
    {
        $this->upload([UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')]);

        $before = Storage::disk(PrivateFile::disk())->allFiles();
        $this->assertCount(1, $before);

        // Force the transaction to fail: drop the table the insert targets.
        \Schema::drop('submission_files');

        try {
            $this->upload([UploadedFile::fake()->create('b.pdf', 5, 'application/pdf')]);
        } catch (\Throwable) {
            // expected
        }

        $after = Storage::disk(PrivateFile::disk())->allFiles();
        $this->assertSame(
            $before,
            $after,
            'A failed insert left its uploaded file behind.'
        );
    }

    public function test_uploads_are_blocked_once_past_the_due_date(): void
    {
        $this->assignment->update(['due_date' => now()->subDay()]);

        $this->upload([UploadedFile::fake()->create('late.pdf', 5, 'application/pdf')])
            ->assertSessionHasErrors('files');

        $this->assertSame(0, SubmissionFile::count());
        $this->assertSame([], Storage::disk(PrivateFile::disk())->allFiles());
    }

    public function test_a_student_from_another_course_cannot_upload(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('student');

        $this->actingAs($outsider)
            ->post(route('submissions.upload', $this->assignment), [
                'files' => [UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')],
            ])
            ->assertForbidden();

        $this->assertSame(0, SubmissionFile::count());
    }

    public function test_disallowed_file_types_are_rejected(): void
    {
        $this->upload([UploadedFile::fake()->create('script.php', 5, 'application/x-php')])
            ->assertSessionHasErrors('files.0');

        $this->assertSame(0, SubmissionFile::count());
    }

    // ------------------------------------------------------------------
    // Direct-to-R2 path
    // ------------------------------------------------------------------

    /** Where this student's objects for this assignment must live. */
    private function prefix(?User $as = null): string
    {
        $user = $as ?? $this->student;

        return 'submissions/'.$this->assignment->section->course_id
            .'/'.$this->assignment->id
            .'/'.$user->id;
    }

    /** Put an object in storage as though the browser had PUT it to R2. */
    private function putObject(string $path, string $contents): string
    {
        Storage::disk(PrivateFile::disk())->put($path, $contents);

        return $path;
    }

    private function register(string $key, string $name = 'essay.pdf', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->student)
            ->postJson(route('submissions.register', $this->assignment), [
                'key' => $key,
                'original_name' => $name,
            ]);
    }

    private function presign(array $payload = [])
    {
        return $this->actingAs($this->student)
            ->postJson(route('submissions.presign', $this->assignment), $payload + [
                'size' => 1024,
                'content_type' => 'application/pdf',
            ]);
    }

    public function test_register_accepts_an_uploaded_object_and_records_it(): void
    {
        $key = $this->putObject($this->prefix().'/abc.pdf', '%PDF-1.4 real enough');

        $this->register($key, 'My Essay.pdf')->assertOk();

        $file = SubmissionFile::firstOrFail();
        $this->assertSame($key, $file->file_path);
        $this->assertSame('My Essay.pdf', $file->original_name);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertGreaterThan(0, $file->size_bytes);
    }

    /**
     * The whole reason register() re-reads the object: the client's declared
     * Content-Type is a claim. A .php file announced as a PDF must not survive
     * simply because the presigned URL said so.
     */
    public function test_register_sniffs_the_real_bytes_and_deletes_a_spoofed_file(): void
    {
        $key = $this->putObject($this->prefix().'/evil.pdf', "<?php system(\$_GET['c']); ?>");

        $this->register($key)->assertStatus(422);

        $this->assertSame(0, SubmissionFile::count());
        Storage::disk(PrivateFile::disk())->assertMissing($key);
    }

    public function test_register_rejects_and_deletes_an_oversized_object(): void
    {
        $this->assignment->update(['max_file_size_mb' => 1]);
        $key = $this->putObject(
            $this->prefix().'/big.pdf',
            '%PDF-1.4'.str_repeat('x', 2 * 1024 * 1024),
        );

        $this->register($key)->assertStatus(422);

        $this->assertSame(0, SubmissionFile::count());
        Storage::disk(PrivateFile::disk())->assertMissing($key);
    }

    /**
     * The key is the only thing the client sends, so it is the only thing an
     * attacker controls. It must be bound to the student it was signed for.
     */
    public function test_register_refuses_a_key_belonging_to_another_student(): void
    {
        $victim = User::factory()->create(['is_active' => true]);
        $victim->assignRole('student');

        $key = $this->putObject($this->prefix($victim).'/theirs.pdf', '%PDF-1.4 victim work');

        $this->register($key)->assertForbidden();

        $this->assertSame(0, SubmissionFile::count());
        // Rejected, but NOT deleted — it isn't ours to remove.
        Storage::disk(PrivateFile::disk())->assertExists($key);
    }

    public function test_register_refuses_a_key_outside_the_submissions_tree(): void
    {
        $key = $this->putObject('materials/1/1/secret.pdf', '%PDF-1.4 course material');

        $this->register($key)->assertForbidden();

        $this->assertSame(0, SubmissionFile::count());
        Storage::disk(PrivateFile::disk())->assertExists($key);
    }

    /**
     * Presign and PUT are separate requests, so a deadline can pass between
     * them. The object lands anyway — register has to reject it and clean up.
     */
    public function test_register_rejects_and_deletes_an_upload_that_landed_after_the_due_date(): void
    {
        $key = $this->putObject($this->prefix().'/late.pdf', '%PDF-1.4 too late');
        $this->assignment->update(['due_date' => now()->subMinute()]);

        $this->register($key)->assertStatus(422);

        $this->assertSame(0, SubmissionFile::count());
        Storage::disk(PrivateFile::disk())->assertMissing($key);
    }

    public function test_register_enforces_the_max_files_cap_and_deletes_the_loser(): void
    {
        foreach (['a', 'b', 'c'] as $n) {
            $this->register($this->putObject($this->prefix()."/{$n}.pdf", '%PDF-1.4 ok'))->assertOk();
        }
        $this->assertSame(3, SubmissionFile::count());   // max_files is 3

        $overflow = $this->putObject($this->prefix().'/d.pdf', '%PDF-1.4 one too many');
        $this->register($overflow)->assertStatus(422);

        $this->assertSame(3, SubmissionFile::count());
        Storage::disk(PrivateFile::disk())->assertMissing($overflow);
    }

    public function test_register_404s_when_the_object_was_never_uploaded(): void
    {
        $this->register($this->prefix().'/never-happened.pdf')->assertNotFound();

        $this->assertSame(0, SubmissionFile::count());
    }

    public function test_presign_rejects_a_file_over_the_size_cap(): void
    {
        $this->assignment->update(['max_file_size_mb' => 10]);

        $this->presign(['size' => 20 * 1024 * 1024])
            ->assertStatus(422)
            ->assertJsonValidationErrors('size');
    }

    public function test_presign_rejects_a_disallowed_content_type(): void
    {
        $this->presign(['content_type' => 'application/x-php'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content_type');
    }

    public function test_presign_is_blocked_once_past_the_due_date(): void
    {
        $this->assignment->update(['due_date' => now()->subDay()]);

        $this->presign()->assertStatus(422);
    }

    public function test_presign_is_blocked_when_the_file_cap_is_already_reached(): void
    {
        foreach (['a', 'b', 'c'] as $n) {
            $this->register($this->putObject($this->prefix()."/{$n}.pdf", '%PDF-1.4 ok'))->assertOk();
        }

        $this->presign()->assertStatus(422);
    }

    /**
     * The page has to render the uploader wired to the right routes and caps.
     * A typo in the x-data config would leave the form falling back to the
     * proxied path forever — which still works, so nothing would look broken.
     */
    public function test_the_assignment_page_renders_the_uploader_wired_up(): void
    {
        $this->actingAs($this->student)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->assertSee('submissionUploader(', false)
            ->assertSee(route('submissions.presign', $this->assignment), false)
            ->assertSee(route('submissions.register', $this->assignment), false)
            // Caps reach the client, so oversized files are caught before upload.
            ->assertSee('maxBytes: '.(50 * 1024 * 1024), false)
            ->assertSee('maxMb: 50', false)
            // The dev/test disk cannot presign, so the form must post to PHP.
            ->assertSee('direct: false', false)
            ->assertSee(route('submissions.upload', $this->assignment), false);
    }

    public function test_a_student_from_another_course_cannot_presign(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('student');

        $this->actingAs($outsider)
            ->postJson(route('submissions.presign', $this->assignment), [
                'size' => 1024,
                'content_type' => 'application/pdf',
            ])
            ->assertForbidden();
    }
}
