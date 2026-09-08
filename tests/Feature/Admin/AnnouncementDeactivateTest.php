<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deactivating an announcement hides it from every recipient without
 * deleting it. The home page and the image route both go through
 * User::visibleAnnouncements(), so one filter covers both.
 */
class AnnouncementDeactivateTest extends TestCase
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

    /**
     * Reset the signed-in state between users. A second actingAs() in one
     * test does not take without this (same as RouteAccessMatrixTest): the
     * first user's session lingers and the request comes back 401.
     */
    private function asGuest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this;
    }

    private function announcement(array $overrides = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'NOTICE_TOGGLED',
            'type' => Announcement::TYPE_TEXT,
            'body' => 'Hello',
            'audience' => 'all',
            'starts_at' => now()->subHour(),
            'created_by_user_id' => $this->admin->id,
        ], $overrides));
    }

    public function test_a_new_announcement_is_active(): void
    {
        $this->assertTrue($this->announcement()->fresh()->is_active);
    }

    public function test_deactivating_hides_it_from_recipients(): void
    {
        $a = $this->announcement();
        $this->actingAs($this->student)->get('/')->assertSee('NOTICE_TOGGLED');

        $this->asGuest()->actingAs($this->admin)
            ->post(route('announcements.deactivate', $a))
            ->assertRedirect(route('announcements.index'));

        $this->assertFalse($a->fresh()->is_active);
        $this->asGuest()->actingAs($this->student)->get('/')->assertOk()->assertDontSee('NOTICE_TOGGLED');
    }

    public function test_activating_shows_it_again(): void
    {
        $a = $this->announcement(['is_active' => false]);
        $this->actingAs($this->student)->get('/')->assertDontSee('NOTICE_TOGGLED');

        $this->asGuest()->actingAs($this->admin)
            ->post(route('announcements.activate', $a))
            ->assertRedirect(route('announcements.index'));

        $this->assertTrue($a->fresh()->is_active);
        $this->asGuest()->actingAs($this->student)->get('/')->assertSee('NOTICE_TOGGLED');
    }

    public function test_the_image_route_is_closed_while_inactive(): void
    {
        $a = $this->announcement([
            'type' => Announcement::TYPE_IMAGE,
            'body' => '',
            'image_path' => 'announcement-images/a.webp',
            'is_active' => false,
        ]);

        $this->actingAs($this->student)
            ->get(route('announcements.image', $a))
            ->assertForbidden();
    }

    public function test_the_admin_list_still_shows_it_with_its_status(): void
    {
        $this->announcement(['is_active' => false]);

        $this->actingAs($this->admin)
            ->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('NOTICE_TOGGLED')
            ->assertSee('Inactive')
            ->assertSee('Activate')
            ->assertDontSee('Deactivate');
    }

    public function test_editing_a_hidden_announcement_does_not_reshow_it(): void
    {
        $a = $this->announcement(['is_active' => false]);

        $this->actingAs($this->admin)
            ->patch(route('announcements.update', $a), [
                'title' => 'Renamed',
                'type' => Announcement::TYPE_TEXT,
                'body' => 'Hello',
                'audience' => 'all',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('announcements.index'));

        $this->assertSame('Renamed', $a->fresh()->title);
        $this->assertFalse($a->fresh()->is_active);
    }

    public function test_hiding_needs_the_edit_permission(): void
    {
        $a = $this->announcement();

        $this->actingAs($this->student)
            ->post(route('announcements.deactivate', $a))
            ->assertForbidden();

        $this->assertTrue($a->fresh()->is_active);
    }
}
