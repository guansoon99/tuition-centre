<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Announcement scheduling, where both ends are optional.
 *
 * A blank start means "live now" and a blank end means "until removed". Those
 * are stored as NULL, which User::visibleAnnouncements() already treats as an
 * absent bound — so the visibility half of this needs no new logic, only
 * proof that it behaves as claimed.
 */
class AnnouncementScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');
    }

    private function submit(array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(route('announcements.store'), array_merge([
            'title' => 'Notice',
            'type' => Announcement::TYPE_TEXT,
            'body' => 'Hello',
            'audience' => 'all',
        ], $overrides));
    }

    public function test_an_announcement_can_be_saved_with_neither_date(): void
    {
        $this->submit()->assertSessionHasNoErrors()->assertRedirect();

        $a = Announcement::firstOrFail();
        $this->assertNull($a->starts_at);
        $this->assertNull($a->ends_at);
    }

    public function test_a_blank_end_leaves_the_announcement_running(): void
    {
        $this->submit(['starts_at' => now()->subDay()->format('Y-m-d H:i')])
            ->assertSessionHasNoErrors();

        $a = Announcement::firstOrFail();
        $this->assertNull($a->ends_at);
        $this->assertTrue($this->visibleToStudent($a));
    }

    public function test_a_blank_start_shows_it_immediately(): void
    {
        $this->submit(['ends_at' => now()->addDay()->format('Y-m-d H:i')])
            ->assertSessionHasNoErrors();

        $a = Announcement::firstOrFail();
        $this->assertNull($a->starts_at);
        $this->assertTrue($this->visibleToStudent($a));
    }

    public function test_an_unbounded_announcement_is_visible(): void
    {
        $this->submit();

        $this->assertTrue($this->visibleToStudent(Announcement::firstOrFail()));
    }

    /** Bounds still apply when they are given. */
    public function test_a_future_start_is_not_visible_yet(): void
    {
        $this->submit(['starts_at' => now()->addWeek()->format('Y-m-d H:i')]);

        $this->assertFalse($this->visibleToStudent(Announcement::firstOrFail()));
    }

    public function test_a_past_end_is_no_longer_visible(): void
    {
        $this->submit([
            'starts_at' => now()->subWeek()->format('Y-m-d H:i'),
            'ends_at' => now()->subDay()->format('Y-m-d H:i'),
        ]);

        $this->assertFalse($this->visibleToStudent(Announcement::firstOrFail()));
    }

    /**
     * after_or_equal:starts_at still has to work when the field it compares
     * against was left blank — a rule referencing an absent field is the kind
     * of thing that silently stops validating.
     */
    public function test_an_end_before_the_start_is_still_rejected(): void
    {
        $this->submit([
            'starts_at' => now()->addWeek()->format('Y-m-d H:i'),
            'ends_at' => now()->format('Y-m-d H:i'),
        ])->assertSessionHasErrors('ends_at');
    }

    public function test_an_end_with_no_start_is_accepted(): void
    {
        $this->submit(['ends_at' => now()->addWeek()->format('Y-m-d H:i')])
            ->assertSessionHasNoErrors();
    }

    private function visibleToStudent(Announcement $a): bool
    {
        return $this->student->visibleAnnouncements()->whereKey($a->getKey())->exists();
    }
}
