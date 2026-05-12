<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AnnouncementTest extends TestCase
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

    /** Helper: build a valid announcement payload with overridable fields. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Default title',
            'body' => 'Default body.',
            'audience' => 'students',
            'starts_at' => now()->subHour()->format('Y-m-d H:i'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i'),
        ], $overrides);
    }

    public function test_announcement_index_renders_for_admin(): void
    {
        $this->actingAs($this->admin)->get('/announcements')->assertOk()->assertSee('Announcements');
    }

    public function test_announcement_create_page_renders_for_admin(): void
    {
        $this->actingAs($this->admin)->get('/announcements/create')->assertOk()->assertSee('Send announcement');
    }

    public function test_non_admin_cannot_access_announcement_page(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($student)->get('/announcements')->assertForbidden();
    }

    public function test_admin_sends_announcement_to_everyone_excluding_other_admins(): void
    {
        $teacher = User::factory()->create(); $teacher->assignRole('teacher');
        $student = User::factory()->create(); $student->assignRole('student');
        $otherAdmin = User::factory()->create(); $otherAdmin->assignRole('admin');

        $this->actingAs($this->admin)->post('/announcements', $this->payload([
            'title' => 'Holiday on Friday',
            'body' => 'Centre is closed this Friday.',
            'audience' => 'all',
        ]))->assertRedirect('/announcements');

        $this->assertSame(1, $teacher->notifications()->count());
        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(0, $this->admin->notifications()->count());
        $this->assertSame(0, $otherAdmin->notifications()->count());

        $note = $student->notifications()->first();
        $this->assertSame('Holiday on Friday', $note->data['title']);
        $this->assertSame('Centre is closed this Friday.', $note->data['body']);
    }

    public function test_admin_can_target_students_only(): void
    {
        $teacher = User::factory()->create(); $teacher->assignRole('teacher');
        $student = User::factory()->create(); $student->assignRole('student');

        $this->actingAs($this->admin)->post('/announcements', $this->payload())
            ->assertRedirect();

        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(0, $teacher->notifications()->count());
        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_inactive_users_do_not_receive_announcements(): void
    {
        $inactive = User::factory()->create(['is_active' => false]);
        $inactive->assignRole('student');
        $active = User::factory()->create(['is_active' => true]);
        $active->assignRole('student');

        $this->actingAs($this->admin)->post('/announcements', $this->payload())
            ->assertRedirect();

        $this->assertSame(0, $inactive->notifications()->count());
        $this->assertSame(1, $active->notifications()->count());
    }

    public function test_topbar_bell_shows_unread_count_for_recipient(): void
    {
        $student = User::factory()->create(['username' => 'shopper', 'password' => 'pw']);
        $student->assignRole('student');

        $student->notify(new AdminAnnouncementNotification('Hi', 'Body'));

        $this->actingAs($student)->get('/')->assertOk()->assertSee('Hi');
    }

    public function test_user_can_mark_a_notification_as_read(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');
        $student->notify(new AdminAnnouncementNotification('Hi', 'Body'));

        $note = $student->notifications()->first();
        $this->assertNull($note->read_at);

        $this->actingAs($student)
            ->post('/notifications/'.$note->id.'/read')
            ->assertRedirect();

        $this->assertNotNull($note->fresh()->read_at);
    }

    public function test_admin_can_send_to_students_of_a_specific_course_only(): void
    {
        $course = Course::factory()->create(['code' => 'TARGET-1']);
        $otherCourse = Course::factory()->create(['code' => 'OTHER-1']);

        $enrolledStudent = User::factory()->create(); $enrolledStudent->assignRole('student');
        $course->students()->attach($enrolledStudent, ['enrolled_at' => now(), 'is_active' => true]);

        $unrelatedStudent = User::factory()->create(); $unrelatedStudent->assignRole('student');
        $otherCourse->students()->attach($unrelatedStudent, ['enrolled_at' => now(), 'is_active' => true]);

        $this->actingAs($this->admin)->post('/announcements', $this->payload([
            'title' => 'Class moved',
            'audience' => 'students',
            'course_id' => $course->id,
        ]))->assertRedirect();

        $this->assertSame(1, $enrolledStudent->notifications()->count());
        $this->assertSame(0, $unrelatedStudent->notifications()->count());

        $note = $enrolledStudent->notifications()->first();
        $this->assertSame('Students of TARGET-1', $note->data['audience_label']);
    }

    public function test_admin_can_send_to_teachers_of_a_specific_course_only(): void
    {
        $course = Course::factory()->create(['code' => 'BIO-1']);
        $otherCourse = Course::factory()->create(['code' => 'CHEM-1']);

        $assignedTeacher = User::factory()->create(); $assignedTeacher->assignRole('teacher');
        $course->teachers()->attach($assignedTeacher, ['assigned_at' => now()]);

        $unrelatedTeacher = User::factory()->create(); $unrelatedTeacher->assignRole('teacher');
        $otherCourse->teachers()->attach($unrelatedTeacher, ['assigned_at' => now()]);

        $this->actingAs($this->admin)->post('/announcements', $this->payload([
            'audience' => 'teachers',
            'course_id' => $course->id,
        ]))->assertRedirect();

        $this->assertSame(1, $assignedTeacher->notifications()->count());
        $this->assertSame(0, $unrelatedTeacher->notifications()->count());
    }

    public function test_everyone_plus_course_targets_both_students_and_teachers_of_that_course(): void
    {
        $course = Course::factory()->create(['code' => 'JOINT-1']);

        $student = User::factory()->create(); $student->assignRole('student');
        $course->students()->attach($student, ['enrolled_at' => now(), 'is_active' => true]);

        $teacher = User::factory()->create(); $teacher->assignRole('teacher');
        $course->teachers()->attach($teacher, ['assigned_at' => now()]);

        $outsider = User::factory()->create(); $outsider->assignRole('student');

        $this->actingAs($this->admin)->post('/announcements', $this->payload([
            'audience' => 'all',
            'course_id' => $course->id,
        ]))->assertRedirect();

        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(1, $teacher->notifications()->count());
        $this->assertSame(0, $outsider->notifications()->count());
    }

    public function test_audience_label_for_unscoped_audience_uses_friendly_name(): void
    {
        $student = User::factory()->create(); $student->assignRole('student');

        $this->actingAs($this->admin)->post('/announcements', $this->payload())
            ->assertRedirect();

        $note = $student->notifications()->first();
        $this->assertSame('All Students', $note->data['audience_label']);
    }

    public function test_no_recipients_returns_validation_error(): void
    {
        $course = Course::factory()->create();

        $this->actingAs($this->admin)->post('/announcements', $this->payload([
            'audience' => 'students',
            'course_id' => $course->id,
        ]))->assertSessionHasErrors('audience');
    }

    public function test_future_starts_at_hides_notification_until_then(): void
    {
        $student = User::factory()->create(['username' => 'futurestu']);
        $student->assignRole('student');

        $this->actingAs($this->admin)->post('/announcements', $this->payload([
            'title' => 'Future',
            'starts_at' => now()->addDay()->format('Y-m-d H:i'),
            'ends_at' => now()->addDays(2)->format('Y-m-d H:i'),
        ]))->assertRedirect();

        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(0, $student->visibleNotifications()->count());
    }

    public function test_past_ends_at_hides_notification_after_window(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($this->admin)->post('/announcements', $this->payload([
            'title' => 'Old',
            'starts_at' => now()->subDays(2)->format('Y-m-d H:i'),
            'ends_at' => now()->subDay()->format('Y-m-d H:i'),
        ]))->assertRedirect();

        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(0, $student->visibleNotifications()->count());
    }

    public function test_notification_inside_window_is_visible(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($this->admin)->post('/announcements', $this->payload([
            'title' => 'Now',
            'starts_at' => now()->subHour()->format('Y-m-d H:i'),
            'ends_at' => now()->addHour()->format('Y-m-d H:i'),
        ]))->assertRedirect();

        $this->assertSame(1, $student->visibleNotifications()->count());
    }

    public function test_ends_at_before_starts_at_fails_validation(): void
    {
        $this->actingAs($this->admin)->post('/announcements', $this->payload([
            'starts_at' => now()->addDay()->format('Y-m-d H:i'),
            'ends_at' => now()->format('Y-m-d H:i'),
        ]))->assertSessionHasErrors('ends_at');
    }

    public function test_missing_start_or_end_fails_validation(): void
    {
        $payload = $this->payload();
        unset($payload['starts_at']);

        $this->actingAs($this->admin)->post('/announcements', $payload)
            ->assertSessionHasErrors('starts_at');
    }

    public function test_user_can_mark_all_notifications_read(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');
        $student->notify(new AdminAnnouncementNotification('A', 'A'));
        $student->notify(new AdminAnnouncementNotification('B', 'B'));

        $this->assertSame(2, $student->unreadNotifications()->count());

        $this->actingAs($student)
            ->post('/notifications/read-all')
            ->assertRedirect();

        $this->assertSame(0, $student->unreadNotifications()->count());
    }

    public function test_admin_can_edit_an_announcement_and_all_recipients_see_the_new_title(): void
    {
        $a = User::factory()->create(); $a->assignRole('student');
        $b = User::factory()->create(); $b->assignRole('student');

        $this->actingAs($this->admin)->post('/announcements', $this->payload([
            'title' => 'Original',
            'body' => 'Old body',
        ]));

        $announcementId = $a->notifications()->first()->data['announcement_id'];

        $this->actingAs($this->admin)
            ->patch('/announcements/'.$announcementId, [
                'title' => 'Updated',
                'body' => 'New body',
                'starts_at' => now()->subHour()->format('Y-m-d H:i'),
                'ends_at' => now()->addDay()->format('Y-m-d H:i'),
            ])
            ->assertRedirect('/announcements');

        $this->assertSame('Updated', $a->notifications()->first()->fresh()->data['title']);
        $this->assertSame('Updated', $b->notifications()->first()->fresh()->data['title']);
        $this->assertSame('New body', $a->notifications()->first()->fresh()->data['body']);
    }

    public function test_admin_can_delete_an_announcement_removing_all_recipient_rows(): void
    {
        $a = User::factory()->create(); $a->assignRole('student');
        $b = User::factory()->create(); $b->assignRole('student');

        $this->actingAs($this->admin)->post('/announcements', $this->payload([
            'title' => 'Doomed',
        ]));

        $this->assertSame(1, $a->notifications()->count());
        $this->assertSame(1, $b->notifications()->count());

        $announcementId = $a->notifications()->first()->data['announcement_id'];

        $this->actingAs($this->admin)
            ->delete('/announcements/'.$announcementId)
            ->assertRedirect('/announcements');

        $this->assertSame(0, $a->notifications()->count());
        $this->assertSame(0, $b->notifications()->count());
    }
}
