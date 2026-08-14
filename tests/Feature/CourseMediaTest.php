<?php

namespace Tests\Feature;

use App\Http\Controllers\CourseMediaController;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use App\Support\CourseMedia;
use App\Support\PublicFile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Media a teacher embeds in lesson rich text.
 *
 * The URL for these is saved into the editor's HTML and lives as long as the
 * lesson does, so it must grant nothing on its own — authorisation happens on
 * every view. That is the whole point of the route, and it is what these
 * cover: an outsider holding a perfectly valid URL still gets nothing.
 */
class CourseMediaTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        // The seeder ships the permissions; 'teacher' is a role an admin
        // creates, so stand it up the same way TeacherCrudTest does.
        $this->seed(RolesAndPermissionsSeeder::class);
        $staff = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $staff->givePermissionTo('sections.manage');

        Storage::fake(CourseMedia::disk());
        Storage::fake(PublicFile::disk());

        $this->course = Course::factory()->create(['is_active' => true]);
        Section::factory()->create([
            'course_id' => $this->course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);

        $this->teacher = User::factory()->create(['is_active' => true]);
        $this->teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    private function putMedia(string $name = 'abc-123.webp', ?Course $course = null): string
    {
        $path = CourseMedia::folder(($course ?? $this->course)->id).'/'.$name;
        Storage::disk(CourseMedia::disk())->put($path, 'GIF89a fake bytes');

        return $name;
    }

    // ---------------- serving ----------------

    public function test_an_enrolled_student_can_view_course_media(): void
    {
        $file = $this->putMedia();

        $this->actingAs($this->student)
            ->get(route('course-media.show', ['course' => $this->course->id, 'file' => $file]))
            ->assertOk();
    }

    public function test_the_teacher_of_the_course_can_view_it(): void
    {
        $file = $this->putMedia();

        $this->actingAs($this->teacher)
            ->get(route('course-media.show', ['course' => $this->course->id, 'file' => $file]))
            ->assertOk();
    }

    /**
     * The reason this route exists. Before, the saved URL was the file itself,
     * so anyone it was forwarded to could fetch it forever.
     */
    public function test_a_user_not_on_the_course_is_refused_even_with_a_valid_url(): void
    {
        $file = $this->putMedia();

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('student');

        $this->actingAs($outsider)
            ->get(route('course-media.show', ['course' => $this->course->id, 'file' => $file]))
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $file = $this->putMedia();

        $this->get(route('course-media.show', ['course' => $this->course->id, 'file' => $file]))
            ->assertRedirect(route('login'));
    }

    /** A student on course A must not read course B's media. */
    public function test_media_from_another_course_is_refused(): void
    {
        $other = Course::factory()->create(['is_active' => true]);
        $file = $this->putMedia('other-1.webp', $other);

        $this->actingAs($this->student)
            ->get(route('course-media.show', ['course' => $other->id, 'file' => $file]))
            ->assertForbidden();
    }

    public function test_a_missing_file_is_a_404_not_a_500(): void
    {
        $this->actingAs($this->student)
            ->get(route('course-media.show', ['course' => $this->course->id, 'file' => 'nope-1.webp']))
            ->assertNotFound();
    }

    /**
     * The filename becomes part of a storage path, so traversal attempts must
     * not reach the filesystem. The route pattern rejects these before the
     * controller, which is the intended behaviour.
     */
    public function test_path_traversal_in_the_filename_does_not_resolve(): void
    {
        foreach (['../../.env', '..%2F..%2F.env', 'a/../../secret.pdf'] as $evil) {
            $this->actingAs($this->student)
                ->get('/courses/'.$this->course->id.'/media/'.$evil)
                ->assertNotFound();
        }
    }

    // ---------------- uploading ----------------

    public function test_a_course_teacher_can_upload_an_image_and_gets_a_gated_url(): void
    {
        $response = $this->actingAs($this->teacher)
            ->post(route('course-media.upload-image', $this->course), [
                'image' => UploadedFile::fake()->image('diagram.png', 400, 300),
            ])->assertOk();

        $url = $response->json('url');

        // The saved URL must point at this app, never at the object store.
        $this->assertStringContainsString('/courses/'.$this->course->id.'/media/', $url);
        $this->assertStringNotContainsString('r2.cloudflarestorage.com', $url);

        // And it must actually resolve, not just look right.
        $this->get($url)->assertOk();
    }

    public function test_uploaded_images_are_re_encoded_to_webp(): void
    {
        $url = $this->actingAs($this->teacher)
            ->post(route('course-media.upload-image', $this->course), [
                'image' => UploadedFile::fake()->image('photo.png', 400, 300),
            ])->json('url');

        $this->assertStringEndsWith('.webp', $url);
    }

    public function test_uploads_land_on_the_private_disk_not_the_public_one(): void
    {
        $this->actingAs($this->teacher)
            ->post(route('course-media.upload-image', $this->course), [
                'image' => UploadedFile::fake()->image('x.png'),
            ])->assertOk();

        $this->assertNotEmpty(Storage::disk(CourseMedia::disk())->allFiles('course-media'));
        $this->assertEmpty(Storage::disk(PublicFile::disk())->allFiles('course-media'));
    }

    /**
     * The endpoints this replaced took no course at all and checked only the
     * sections.manage permission, so a teacher could upload against a course
     * they had nothing to do with.
     */
    public function test_a_teacher_cannot_upload_to_a_course_they_do_not_teach(): void
    {
        $other = Course::factory()->create(['is_active' => true]);

        $this->actingAs($this->teacher)
            ->post(route('course-media.upload-image', $other), [
                'image' => UploadedFile::fake()->image('x.png'),
            ])->assertForbidden();
    }

    public function test_a_student_cannot_upload(): void
    {
        $this->actingAs($this->student)
            ->post(route('course-media.upload-image', $this->course), [
                'image' => UploadedFile::fake()->image('x.png'),
            ])->assertForbidden();
    }

    public function test_a_disallowed_image_type_is_rejected(): void
    {
        $this->actingAs($this->teacher)
            ->post(route('course-media.upload-image', $this->course), [
                'image' => UploadedFile::fake()->create('script.php', 10, 'application/x-php'),
            ])->assertSessionHasErrors('image');
    }

    public function test_a_disallowed_video_type_is_rejected(): void
    {
        $this->actingAs($this->teacher)
            ->post(route('course-media.upload-video', $this->course), [
                'video' => UploadedFile::fake()->create('sneaky.mp4', 10, 'application/x-php'),
            ])->assertSessionHasErrors('video');
    }

    // ---------------- direct-to-R2 video ----------------

    private function presignVideo(array $payload = [])
    {
        return $this->actingAs($this->teacher)
            ->postJson(route('course-media.presign-video', $this->course), $payload + [
                'size' => 1024,
                'content_type' => 'video/mp4',
            ]);
    }

    /**
     * Guard, not a feature test. Storage::fake cannot sign, so presign's
     * success path is only exercisable against a real bucket — every video
     * test below therefore covers rejections only. If someone points the test
     * disk at S3, this fails and says so, rather than the others quietly
     * starting to mean something different.
     */
    public function test_the_test_disk_cannot_presign_so_only_rejections_are_covered(): void
    {
        $this->assertFalse(CourseMedia::canPresign());
    }

    public function test_presign_video_rejects_a_file_over_the_cap(): void
    {
        $over = (CourseMediaController::MAX_VIDEO_MB + 1) * 1024 * 1024;

        $this->presignVideo(['size' => $over])
            ->assertStatus(422)
            ->assertJsonValidationErrors('size');
    }

    public function test_presign_video_rejects_a_non_video_content_type(): void
    {
        $this->presignVideo(['content_type' => 'application/x-php'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content_type');
    }

    public function test_a_teacher_cannot_presign_for_a_course_they_do_not_teach(): void
    {
        $other = Course::factory()->create(['is_active' => true]);

        $this->actingAs($this->teacher)
            ->postJson(route('course-media.presign-video', $other), [
                'size' => 1024, 'content_type' => 'video/mp4',
            ])->assertForbidden();
    }

    public function test_a_student_cannot_presign(): void
    {
        $this->actingAs($this->student)
            ->postJson(route('course-media.presign-video', $this->course), [
                'size' => 1024, 'content_type' => 'video/mp4',
            ])->assertForbidden();
    }

    /**
     * R2 signs only the host, so nothing about the upload is constrained at
     * PUT time. register is where size and type are actually enforced — on
     * bytes that already exist in the bucket.
     */
    public function test_register_video_deletes_an_object_that_is_not_really_video(): void
    {
        $name = 'cccc1111-2222-3333-4444-555566667777.mp4';
        $this->putMedia($name);   // plain text, not video

        $this->actingAs($this->teacher)
            ->postJson(route('course-media.register-video', $this->course), ['name' => $name])
            ->assertStatus(422);

        Storage::disk(CourseMedia::disk())
            ->assertMissing(CourseMedia::folder($this->course->id).'/'.$name);
    }

    public function test_register_video_404s_when_nothing_was_uploaded(): void
    {
        $this->actingAs($this->teacher)
            ->postJson(route('course-media.register-video', $this->course), [
                'name' => 'dddd1111-2222-3333-4444-555566667777.mp4',
            ])->assertNotFound();
    }

    public function test_register_video_refuses_a_name_that_escapes_the_course_folder(): void
    {
        $this->actingAs($this->teacher)
            ->postJson(route('course-media.register-video', $this->course), [
                'name' => '../../submissions/1/2/8/stolen.pdf',
            ])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_the_proxied_video_fallback_still_enforces_the_cap(): void
    {
        $overMb = CourseMediaController::MAX_VIDEO_MB + 1;

        $this->actingAs($this->teacher)
            ->post(route('course-media.upload-video', $this->course), [
                'video' => UploadedFile::fake()->create('huge.mp4', $overMb * 1024, 'video/mp4'),
            ])->assertSessionHasErrors('video');
    }

    public function test_video_keeps_its_extension_from_the_sniffed_mime_type(): void
    {
        $url = $this->actingAs($this->teacher)
            ->post(route('course-media.upload-video', $this->course), [
                'video' => UploadedFile::fake()->create('lesson.mp4', 64, 'video/mp4'),
            ])->json('url');

        $this->assertStringEndsWith('.mp4', $url);
        $this->get($url)->assertOk();
    }
}
