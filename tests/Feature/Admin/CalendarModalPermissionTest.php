<?php

namespace Tests\Feature\Admin;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Who gets to open the calendar's event modal.
 *
 * Reading the calendar is open to every signed-in user, so a student can see
 * the grid and the events on it. The modal is a different thing: it is the
 * editing surface, and opening it with no write permission produced a dialog
 * with every field disabled and nothing to do but close it.
 *
 * The flags below are the contract the Alpine component runs on — it refuses
 * to open the modal unless one of them is true — so these tests pin the values
 * the server prints into the page. The routes are covered separately at the
 * bottom, because a hidden control is a courtesy and the request is what has
 * to be refused.
 */
class CalendarModalPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('student');

        return $user;
    }

    /** A user holding exactly the calendar permissions named. */
    private function userWith(array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo($permission);
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function calendarHtml(User $as): string
    {
        return $this->actingAs($as)->get('/calendar')->assertOk()->getContent();
    }

    /** events.created_by_user_id is NOT NULL, so an author is required. */
    private function existingEvent(): Event
    {
        return Event::create([
            'title' => 'Sports Day',
            'date' => '2026-08-26',
            'color' => Event::COLOR_DEFAULT,
            'display_style' => Event::STYLE_DEFAULT,
            'created_by_user_id' => User::factory()->create()->id,
        ]);
    }

    // ---- The flags the component reads --------------------------------------

    public function test_a_student_gets_every_calendar_flag_false(): void
    {
        $html = $this->calendarHtml($this->student());

        $this->assertStringContainsString('canCreate: false', $html);
        $this->assertStringContainsString('canEdit: false', $html);
        $this->assertStringContainsString('canDelete: false', $html);
    }

    public function test_a_user_with_create_permission_gets_that_flag_true(): void
    {
        $html = $this->calendarHtml($this->userWith(['calendar.create']));

        $this->assertStringContainsString('canCreate: true', $html);
        $this->assertStringContainsString('canEdit: false', $html);
    }

    /** No create permission means no store URL to post to either. */
    public function test_a_student_is_given_no_store_url(): void
    {
        $this->assertStringContainsString("storeUrl: ''", $this->calendarHtml($this->student()));
    }

    public function test_a_creator_is_given_a_store_url(): void
    {
        $this->assertStringContainsString(
            'storeUrl: '.json_encode(route('calendar.events.store'), JSON_UNESCAPED_SLASHES),
            str_replace("'", '"', $this->calendarHtml($this->userWith(['calendar.create']))),
        );
    }

    // ---- The pointer cursor -------------------------------------------------

    /**
     * The rule lives in @push('head'), which renders after @endsection — this
     * asserts the permission variables from the top of the view are still in
     * scope down there, which is not obvious from reading the Blade.
     */
    public function test_the_clickable_cursor_is_not_offered_to_a_student(): void
    {
        $this->assertStringNotContainsString(
            'fc-day-has-user-bg { cursor: pointer; }',
            $this->calendarHtml($this->student()),
            'A student should not get a pointer over a cell that ignores the click.',
        );
    }

    public function test_the_clickable_cursor_is_offered_to_an_editor(): void
    {
        $this->assertStringContainsString(
            'fc-day-has-user-bg { cursor: pointer; }',
            $this->calendarHtml($this->userWith(['calendar.edit'])),
        );
    }

    /** Delete alone is still a reason to reach the modal. */
    public function test_a_delete_only_role_keeps_the_cursor(): void
    {
        $this->assertStringContainsString(
            'fc-day-has-user-bg { cursor: pointer; }',
            $this->calendarHtml($this->userWith(['calendar.delete'])),
        );
    }

    // ---- The guard itself ---------------------------------------------------

    /**
     * A presence check, and honest about being one.
     *
     * The thing that actually stops the modal is a line of Alpine, which
     * PHPUnit never runs — so this cannot prove the behaviour, only that the
     * guard has not been deleted. The flags above and the routes below are
     * the parts under real test.
     */
    public function test_the_modal_opener_still_carries_its_permission_guard(): void
    {
        $html = $this->calendarHtml($this->userWith(['calendar.create']));

        $this->assertStringContainsString('canOpenModal', $html);
        $this->assertStringContainsString('if (! this.canOpenModal) return;', $html);
    }

    // ---- Still readable -----------------------------------------------------

    /** Locking the modal must not lock the page. */
    public function test_a_student_can_still_load_the_calendar_and_its_feed(): void
    {
        $student = $this->student();

        $this->actingAs($student)->get('/calendar')->assertOk();
        $this->actingAs($student)->get('/calendar/events')->assertOk();
    }

    // ---- What actually stops them -------------------------------------------

    public function test_a_student_cannot_create_an_event(): void
    {
        $this->actingAs($this->student())
            ->post('/calendar/events', ['title' => 'Sneaked in', 'date' => '2026-09-01'])
            ->assertForbidden();

        $this->assertSame(0, Event::count());
    }

    public function test_a_student_cannot_edit_an_event(): void
    {
        $event = $this->existingEvent();

        $this->actingAs($this->student())
            ->patch('/calendar/events/'.$event->id, ['title' => 'Renamed', 'date' => '2026-08-26'])
            ->assertForbidden();

        $this->assertSame('Sports Day', $event->fresh()->title);
    }

    public function test_a_student_cannot_delete_an_event(): void
    {
        $event = $this->existingEvent();

        $this->actingAs($this->student())
            ->delete('/calendar/events/'.$event->id)
            ->assertForbidden();

        $this->assertNotNull($event->fresh());
    }
}
