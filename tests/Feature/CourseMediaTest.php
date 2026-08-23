<?php

namespace Tests\Feature;

use App\Http\Controllers\CourseMediaController;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
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
        $path = CourseMedia::materialsFolder(($course ?? $this->course)->id).'/'.$name;
        Storage::disk(CourseMedia::disk())->put($path, 'GIF89a fake bytes');

        return $name;
    }

    // ---------------- serving ----------------

    public function test_an_enrolled_student_can_view_course_media(): void
    {
        $file = $this->putMedia();

        $this->actingAs($this->student)
            ->get(route('course-media.show', ['course' => $this->course->id, 'folder' => 'materials', 'file' => $file]))
            ->assertOk();
    }

    public function test_the_teacher_of_the_course_can_view_it(): void
    {
        $file = $this->putMedia();

        $this->actingAs($this->teacher)
            ->get(route('course-media.show', ['course' => $this->course->id, 'folder' => 'materials', 'file' => $file]))
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
            ->get(route('course-media.show', ['course' => $this->course->id, 'folder' => 'materials', 'file' => $file]))
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $file = $this->putMedia();

        $this->get(route('course-media.show', ['course' => $this->course->id, 'folder' => 'materials', 'file' => $file]))
            ->assertRedirect(route('login'));
    }

    /** A student on course A must not read course B's media. */
    public function test_media_from_another_course_is_refused(): void
    {
        $other = Course::factory()->create(['is_active' => true]);
        $file = $this->putMedia('other-1.webp', $other);

        $this->actingAs($this->student)
            ->get(route('course-media.show', ['course' => $other->id, 'folder' => 'materials', 'file' => $file]))
            ->assertForbidden();
    }

    public function test_a_missing_file_is_a_404_not_a_500(): void
    {
        $this->actingAs($this->student)
            ->get(route('course-media.show', ['course' => $this->course->id, 'folder' => 'materials', 'file' => 'nope-1.webp']))
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
                ->get('/courses/'.$this->course->id.'/media/materials/'.$evil)
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

    // ---------------- editor wiring ----------------

    /**
     * Guards against a broken edit to the Quill toolbar handlers.
     *
     * These live as inline JS inside Blade, so nothing type-checks or parses
     * them — a mangled edit compiles fine, passes every other test here, and
     * only fails in the browser with "Unexpected token ')'". That happened:
     * a regex replace stopped at the wrong brace and left half the old handler
     * behind, duplicating its body.
     *
     * Exact counts catch precisely that, because the failure duplicates code
     * rather than removing it.
     */
    public function test_the_editor_page_wires_each_upload_handler_exactly_once(): void
    {
        $material = $this->materialEmbedding([]);

        $html = $this->actingAs($this->teacher)
            ->get(route('materials.edit', $material))
            ->assertOk()
            ->getContent();

        $expectations = [
            // One overlay per handler: image and video.
            'window.courseMediaOverlay(' => 2,
            // Video goes through the shared uploader, exactly once.
            'window.uploadCourseVideo(' => 1,
            // The image handler posts to this endpoint once. A duplicated
            // handler body would make it 2.
            route('course-media.upload-image', $this->course) => 1,
            // Leftovers from a half-replaced handler would repeat these.
            "form.append('image', file)" => 1,
            // Once only, inside the shared uploader's proxied() fallback —
            // never inline in the toolbar handler, which is where the old
            // pre-presign version put it.
            "form.append('video', file)" => 1,
        ];

        foreach ($expectations as $needle => $times) {
            $this->assertSame(
                $times,
                substr_count($html, $needle),
                "Expected [{$needle}] exactly {$times}x in the editor page — a different count "
                .'means a toolbar handler was duplicated or dropped by a bad edit.',
            );
        }

        // The toolbar handler must delegate, not do its own fetch. The old
        // version posted the video straight from here.
        $this->assertStringNotContainsString(
            "const res = await fetch('".route('course-media.upload-video', $this->course)."'",
            $html,
        );
    }

    /**
     * The submit spinner is wired from the layout, so every page with a form
     * gets it without being edited. That also means nothing on an individual
     * page would reveal it going missing.
     */
    public function test_the_submit_spinner_ships_on_every_authenticated_page(): void
    {
        $material = $this->materialEmbedding([]);

        $html = $this->actingAs($this->teacher)
            ->get(route('materials.edit', $material))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('cm-spinner', $html, 'Spinner CSS missing.');
        $this->assertStringContainsString('cmSubmitting', $html, 'Submit handler missing.');

        // Defined once from the layout, not per-page — two copies would mean
        // the listener runs twice and double-blocks a legitimate submit.
        $this->assertSame(
            1,
            substr_count($html, "form.dataset.cmSubmitting = '1'"),
            'The submit handler must be registered exactly once per page.',
        );
    }

    /**
     * The student submission form runs its own XHR upload with a per-file
     * progress bar, so the generic spinner must stay out of its way.
     */
    public function test_the_submission_form_opts_out_of_the_generic_spinner(): void
    {
        $assignment = Material::create([
            'section_id' => $this->course->sections()->first()->id,
            'title' => 'Essay', 'type' => Material::TYPE_ASSIGNMENT,
            'due_date' => now()->addWeek(), 'max_files' => 3, 'max_file_size_mb' => 50,
            'sort_order' => 2, 'is_published' => true, 'published_at' => now(),
        ]);

        $this->actingAs($this->student)
            ->get(route('materials.view', $assignment))
            ->assertOk()
            ->assertSee('data-no-spinner', false);
    }

    // ---------------- course banner ----------------

    /**
     * The banner moved from the public bucket to here, so it now goes through
     * the same gate as lesson media. Its URL must point at this app, never at
     * an object-store address anyone could fetch.
     */
    public function test_a_course_banner_is_served_through_the_gated_route(): void
    {
        $file = 'bbbb0000-0000-0000-0000-00000000000f.webp';
        $path = CourseMedia::bannerFolder($this->course->id).'/'.$file;
        Storage::disk(CourseMedia::disk())->put($path, 'banner');
        $this->course->update(['banner_image' => $path]);

        $url = $this->course->fresh()->banner_image_url;

        $this->assertStringContainsString('/courses/'.$this->course->id.'/media/', $url);
        $this->assertStringNotContainsString('r2.cloudflarestorage.com', $url);
        $this->assertStringNotContainsString('r2.dev', $url);

        $this->actingAs($this->student)->get($url)->assertOk();
    }

    public function test_a_course_banner_is_refused_to_someone_not_on_the_course(): void
    {
        $file = 'bbbb0000-0000-0000-0000-000000000010.webp';
        $path = CourseMedia::bannerFolder($this->course->id).'/'.$file;
        Storage::disk(CourseMedia::disk())->put($path, 'banner');
        $this->course->update(['banner_image' => $path]);

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('student');

        $this->actingAs($outsider)
            ->get($this->course->fresh()->banner_image_url)
            ->assertForbidden();
    }

    /**
     * The banner sits in course-media/ but is referenced by a column, not by
     * any lesson body — so the sweep would treat it as an orphan and delete
     * every course banner on its first run.
     */
    public function test_the_orphan_sweep_does_not_delete_a_course_banner(): void
    {
        $path = CourseMedia::bannerFolder($this->course->id).'/bbbb0000-0000-0000-0000-000000000011.webp';
        Storage::disk(CourseMedia::disk())->put($path, 'banner');
        $this->course->update(['banner_image' => $path]);

        $this->artisan('submissions:sweep-orphans', ['--hours' => 0])->assertSuccessful();

        Storage::disk(CourseMedia::disk())->assertExists($path);
    }

    /**
     * Banners share the course-media folder with lesson images. Deleting a
     * material must not take the banner with it.
     */
    public function test_deleting_a_material_leaves_the_course_banner_alone(): void
    {
        $bannerFile = 'bbbb0000-0000-0000-0000-000000000012.webp';
        $bannerPath = CourseMedia::bannerFolder($this->course->id).'/'.$bannerFile;
        Storage::disk(CourseMedia::disk())->put($bannerPath, 'banner');
        $this->course->update(['banner_image' => $bannerPath]);

        $embedded = $this->putMedia('bbbb0000-0000-0000-0000-000000000013.webp');
        $material = $this->materialEmbedding([$embedded]);

        $this->actingAs($this->teacher)
            ->delete(route('materials.destroy', $material))
            ->assertRedirect();

        $disk = Storage::disk(CourseMedia::disk());
        $disk->assertExists($bannerPath);
        $disk->assertMissing(CourseMedia::folder($this->course->id).'/'.$embedded);
    }

    // ---------------- cleanup ----------------

    private function materialEmbedding(array $names): Material
    {
        $body = '';
        foreach ($names as $n) {
            $body .= '<img src="/courses/'.$this->course->id.'/media/materials/'.$n.'">';
        }

        return Material::create([
            'section_id' => $this->course->sections()->first()->id,
            'title' => 'Lesson', 'type' => Material::TYPE_MEDIA, 'body' => $body,
            'sort_order' => 1, 'is_published' => true, 'published_at' => now(),
        ]);
    }

    private function mediaExists(string $name): bool
    {
        return Storage::disk(CourseMedia::disk())
            ->exists(CourseMedia::materialsFolder($this->course->id).'/'.$name);
    }

    /**
     * The gap this covers: PDFs were cleaned up on delete because they live in
     * a column, but media embedded in the body never was — there was nothing
     * hooked to it.
     */
    public function test_deleting_a_material_deletes_the_media_embedded_in_it(): void
    {
        $name = 'aaaa0000-0000-0000-0000-00000000000a.webp';
        $this->putMedia($name);
        $material = $this->materialEmbedding([$name]);

        $this->actingAs($this->teacher)
            ->delete(route('materials.destroy', $material))
            ->assertRedirect();

        $this->assertFalse($this->mediaExists($name));
    }

    /**
     * A teacher copy-pasting a diagram between two lessons leaves both bodies
     * pointing at one object. Deleting the first lesson must not blank the
     * image in the second.
     */
    public function test_media_still_used_by_another_material_is_kept(): void
    {
        $shared = 'aaaa0000-0000-0000-0000-00000000000b.webp';
        $solo = 'aaaa0000-0000-0000-0000-00000000000c.webp';
        $this->putMedia($shared);
        $this->putMedia($solo);

        $this->materialEmbedding([$shared]);                       // the other lesson
        $doomed = $this->materialEmbedding([$shared, $solo]);

        $this->actingAs($this->teacher)
            ->delete(route('materials.destroy', $doomed))
            ->assertRedirect();

        $this->assertTrue($this->mediaExists($shared), 'Shared media must survive.');
        $this->assertFalse($this->mediaExists($solo));
    }

    public function test_removing_an_image_while_editing_deletes_it(): void
    {
        $kept = 'aaaa0000-0000-0000-0000-00000000000d.webp';
        $removed = 'aaaa0000-0000-0000-0000-00000000000e.webp';
        $this->putMedia($kept);
        $this->putMedia($removed);

        $material = $this->materialEmbedding([$kept, $removed]);

        $this->actingAs($this->teacher)
            ->patch(route('materials.update', $material), [
                'title' => 'Lesson',
                'type' => Material::TYPE_MEDIA,
                'body' => '<img src="/courses/'.$this->course->id.'/media/'.$kept.'">',
            ])->assertRedirect();

        $this->assertTrue($this->mediaExists($kept));
        $this->assertFalse($this->mediaExists($removed));
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
