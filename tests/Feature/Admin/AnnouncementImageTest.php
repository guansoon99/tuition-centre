<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\PrivateFile;
use App\Support\PublicFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Announcement images are private.
 *
 * An announcement can be scoped to a single course or a single role, so its
 * image must not be fetchable by anyone holding the URL. It's stored off the
 * web root and streamed by AnnouncementImageController, which checks the
 * caller is in the announcement's audience.
 */
class AnnouncementImageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeStudent(?Course $enrolledIn = null): User
    {
        $student = User::factory()->create(['is_active' => true]);
        $student->assignRole('student');

        if ($enrolledIn) {
            Enrollment::create([
                'course_id' => $enrolledIn->id,
                'user_id' => $student->id,
                'role_on_course' => Enrollment::ROLE_STUDENT,
                'is_active' => true,
                'enrolled_at' => now(),
            ]);
        }

        return $student;
    }

    private function makeImageAnnouncement(array $attrs = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'Notice',
            'body' => '',
            'type' => Announcement::TYPE_IMAGE,
            'image_path' => 'announcement-images/notice.webp',
            'audience' => 'all',
            'course_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'sort_order' => 1,
            'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    public function test_uploaded_image_lands_on_the_private_disk_not_the_public_one(): void
    {
        Storage::fake(PublicFile::disk());
        Storage::fake(PrivateFile::disk());

        $this->actingAs($this->admin)->post('/announcements', [
            'title' => 'Sports Day',
            'type' => Announcement::TYPE_IMAGE,
            'image' => UploadedFile::fake()->image('poster.jpg', 400, 300),
            'audience' => 'all',
            'starts_at' => now()->subDay()->format('Y-m-d H:i'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertRedirect();

        $announcement = Announcement::firstOrFail();
        $this->assertNotNull($announcement->image_path);

        Storage::disk(PrivateFile::disk())->assertExists($announcement->image_path);
        Storage::disk(PublicFile::disk())->assertMissing($announcement->image_path);
    }

    public function test_image_url_points_at_the_gated_route_not_storage(): void
    {
        $announcement = $this->makeImageAnnouncement();

        $this->assertSame(route('announcements.image', $announcement), $announcement->image_url);
        $this->assertStringNotContainsString('/storage/', $announcement->image_url);
    }

    public function test_a_user_in_the_audience_can_fetch_the_image(): void
    {
        Storage::fake(PrivateFile::disk());
        Storage::disk(PrivateFile::disk())->put('announcement-images/notice.webp', 'fake-bytes');

        $announcement = $this->makeImageAnnouncement();
        $student = $this->makeStudent();

        $this->actingAs($student)
            ->get(route('announcements.image', $announcement))
            ->assertOk()
            ->assertHeader('content-type', 'image/webp');
    }

    /**
     * The reason these moved off the public disk: a course-scoped
     * announcement's image must not leak to students in other courses.
     */
    public function test_a_student_outside_a_course_scoped_audience_is_refused(): void
    {
        Storage::fake(PrivateFile::disk());
        Storage::disk(PrivateFile::disk())->put('announcement-images/notice.webp', 'fake-bytes');

        $theirCourse = Course::factory()->create();
        $otherCourse = Course::factory()->create();

        $announcement = $this->makeImageAnnouncement(['course_id' => $theirCourse->id]);
        $outsider = $this->makeStudent($otherCourse);

        $this->actingAs($outsider)
            ->get(route('announcements.image', $announcement))
            ->assertForbidden();
    }

    public function test_an_enrolled_student_can_fetch_a_course_scoped_image(): void
    {
        Storage::fake(PrivateFile::disk());
        Storage::disk(PrivateFile::disk())->put('announcement-images/notice.webp', 'fake-bytes');

        $course = Course::factory()->create();
        $announcement = $this->makeImageAnnouncement(['course_id' => $course->id]);
        $insider = $this->makeStudent($course);

        $this->actingAs($insider)
            ->get(route('announcements.image', $announcement))
            ->assertOk();
    }

    public function test_guests_cannot_fetch_an_announcement_image(): void
    {
        $announcement = $this->makeImageAnnouncement();

        $this->get(route('announcements.image', $announcement))
            ->assertRedirect('/login');
    }

    public function test_missing_file_404s_rather_than_erroring(): void
    {
        Storage::fake(PrivateFile::disk());

        $announcement = $this->makeImageAnnouncement(['image_path' => 'announcement-images/gone.webp']);

        $this->actingAs($this->admin)
            ->get(route('announcements.image', $announcement))
            ->assertNotFound();
    }

    public function test_deleting_an_announcement_removes_its_private_file(): void
    {
        Storage::fake(PrivateFile::disk());
        Storage::disk(PrivateFile::disk())->put('announcement-images/notice.webp', 'fake-bytes');

        $announcement = $this->makeImageAnnouncement();

        $this->actingAs($this->admin)
            ->delete("/announcements/{$announcement->id}")
            ->assertRedirect();

        Storage::disk(PrivateFile::disk())->assertMissing('announcement-images/notice.webp');
    }
}
