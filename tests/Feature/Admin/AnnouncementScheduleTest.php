<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Announcement scheduling.
 *
 * The start defaults to now rather than being stored null, so the list always
 * shows a date and the record says when the announcement actually went up.
 * The end is genuinely optional: null there means it never expires, which
 * User::visibleAnnouncements() already reads as an absent bound.
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
        $this->assertNull($a->ends_at);
        $this->assertNotNull($a->starts_at, 'Start should fall back to now, not stay blank.');
    }

    public function test_an_omitted_start_is_stored_as_now(): void
    {
        $this->travelTo(now()->startOfMinute());

        $this->submit()->assertSessionHasNoErrors();

        $this->assertTrue(
            Announcement::firstOrFail()->starts_at->equalTo(now()->startOfMinute()),
            'A blank start should be saved as the current moment.',
        );
    }

    public function test_the_create_form_pre_fills_the_start_with_now(): void
    {
        $this->actingAs($this->admin)
            ->get(route('announcements.create'))
            ->assertOk()
            ->assertSee('value="'.now()->format('Y-m-d H:i').'"', false);
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

        $this->assertTrue($this->visibleToStudent(Announcement::firstOrFail()));
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
