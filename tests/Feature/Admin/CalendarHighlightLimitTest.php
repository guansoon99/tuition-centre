<?php

namespace Tests\Feature\Admin;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * One highlight a day, as many bars as you like.
 *
 * Two highlights on one date are two background events tinting the same cell:
 * the second paints over the first, so the calendar shows one colour while the
 * other event still exists, invisible. Bars stack visibly and are unrestricted.
 *
 * The cap is enforced in EventRequest, which is the part that matters — the
 * greyed-out radio in the modal is only a courtesy.
 */
class CalendarHighlightLimitTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-09-15';

    private function editor(): User
    {
        $role = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $role->givePermissionTo('calendar.create');
        $role->givePermissionTo('calendar.edit');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeEvent(string $style, string $date = self::DAY, string $title = 'Existing'): Event
    {
        return Event::create([
            'title' => $title,
            'date' => $date,
            'color' => 'blue',
            'display_style' => $style,
            'created_by_user_id' => User::factory()->create()->id,
        ]);
    }

    private function createEvent(User $as, array $payload)
    {
        return $this->actingAs($as)->postJson('/calendar/events', array_merge([
            'title' => 'New Event',
            'date' => self::DAY,
        ], $payload));
    }

    // ---- Bars are unlimited -------------------------------------------------

    public function test_many_bars_can_share_one_day(): void
    {
        $user = $this->editor();

        foreach (['First', 'Second', 'Third'] as $title) {
            $this->createEvent($user, ['title' => $title, 'display_style' => Event::STYLE_PILL])
                ->assertOk();
        }

        $this->assertSame(3, Event::whereDate('date', self::DAY)->count());
    }

    /**
     * The case that prompted all this: an existing highlight must not stand in
     * the way of adding bars to the same day.
     */
    public function test_a_bar_can_be_added_to_a_day_that_already_has_a_highlight(): void
    {
        $this->makeEvent(Event::STYLE_BACKGROUND, title: 'The Highlight');

        $this->createEvent($this->editor(), ['title' => 'A Bar', 'display_style' => Event::STYLE_PILL])
            ->assertOk();

        $this->assertNotNull(Event::where('title', 'A Bar')->first());
    }

    /** And the default type, when none is sent, is a bar — so also unlimited. */
    public function test_an_event_with_no_type_given_is_a_bar_and_is_unrestricted(): void
    {
        $this->makeEvent(Event::STYLE_BACKGROUND);
        $user = $this->editor();

        $this->createEvent($user, ['title' => 'Untyped One'])->assertOk();
        $this->createEvent($user, ['title' => 'Untyped Two'])->assertOk();

        $this->assertSame(
            Event::STYLE_PILL,
            Event::where('title', 'Untyped One')->value('display_style'),
        );
    }

    // ---- Highlights are capped at one ---------------------------------------

    public function test_a_second_highlight_on_the_same_day_is_refused(): void
    {
        $this->makeEvent(Event::STYLE_BACKGROUND, title: 'The Highlight');

        $this->createEvent($this->editor(), ['title' => 'Another', 'display_style' => Event::STYLE_BACKGROUND])
            ->assertStatus(422)
            ->assertJsonValidationErrors('display_style');

        $this->assertSame(
            1,
            Event::whereDate('date', self::DAY)->where('display_style', Event::STYLE_BACKGROUND)->count(),
        );
    }

    public function test_a_highlight_is_allowed_on_a_day_that_only_has_bars(): void
    {
        $this->makeEvent(Event::STYLE_PILL, title: 'Bar One');
        $this->makeEvent(Event::STYLE_PILL, title: 'Bar Two');

        $this->createEvent($this->editor(), ['title' => 'The Highlight', 'display_style' => Event::STYLE_BACKGROUND])
            ->assertOk();
    }

    public function test_highlights_on_different_days_do_not_clash(): void
    {
        $this->makeEvent(Event::STYLE_BACKGROUND, date: '2026-09-14');

        $this->createEvent($this->editor(), ['display_style' => Event::STYLE_BACKGROUND])
            ->assertOk();
    }

    // ---- The Type switch ----------------------------------------------------

    /**
     * Presence check on the client-side mode switch — Alpine, so PHPUnit does
     * not run it.
     *
     * On a day that already has a highlight, choosing Highlight in the modal
     * loads that event for editing instead of starting a second one, and
     * choosing Bar empties the form back out for a new bar. That is what keeps
     * both actions reachable from a single click on the day.
     */
    public function test_the_modal_carries_the_type_mode_switch(): void
    {
        $html = $this->actingAs($this->editor())->get('/calendar')->assertOk()->getContent();

        $this->assertStringContainsString('watchTypeSwitch', $html);
        $this->assertStringContainsString("this.\$watch('modal.displayStyle'", $html);
        $this->assertStringContainsString('userHighlightOn', $html);

        // The radio must not be disabled any more — disabling it was the old
        // behaviour and would block the switch entirely.
        $this->assertStringNotContainsString('typeDisabled', $html);
    }

    // ---- Editing ------------------------------------------------------------

    /** An event is not its own clash — saving it unchanged must still work. */
    public function test_the_existing_highlight_can_be_edited_without_tripping_the_rule(): void
    {
        $highlight = $this->makeEvent(Event::STYLE_BACKGROUND, title: 'Before');

        $this->actingAs($this->editor())
            ->patchJson('/calendar/events/'.$highlight->id, [
                'title' => 'After',
                'date' => self::DAY,
                'display_style' => Event::STYLE_BACKGROUND,
            ])
            ->assertOk();

        $this->assertSame('After', $highlight->fresh()->title);
    }

    public function test_turning_a_bar_into_a_highlight_is_refused_when_one_exists(): void
    {
        $this->makeEvent(Event::STYLE_BACKGROUND, title: 'The Highlight');
        $bar = $this->makeEvent(Event::STYLE_PILL, title: 'A Bar');

        $this->actingAs($this->editor())
            ->patchJson('/calendar/events/'.$bar->id, [
                'title' => 'A Bar',
                'date' => self::DAY,
                'display_style' => Event::STYLE_BACKGROUND,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('display_style');

        $this->assertSame(Event::STYLE_PILL, $bar->fresh()->display_style);
    }

    /** Moving a highlight onto a day that already has one is the same clash. */
    public function test_moving_a_highlight_onto_an_occupied_day_is_refused(): void
    {
        $this->makeEvent(Event::STYLE_BACKGROUND, title: 'Sitting There');
        $other = $this->makeEvent(Event::STYLE_BACKGROUND, date: '2026-09-14', title: 'Moving');

        $this->actingAs($this->editor())
            ->patchJson('/calendar/events/'.$other->id, [
                'title' => 'Moving',
                'date' => self::DAY,
                'display_style' => Event::STYLE_BACKGROUND,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('display_style');
    }

    public function test_a_bar_can_always_move_onto_a_day_with_a_highlight(): void
    {
        $this->makeEvent(Event::STYLE_BACKGROUND, title: 'Sitting There');
        $bar = $this->makeEvent(Event::STYLE_PILL, date: '2026-09-14', title: 'Moving Bar');

        $this->actingAs($this->editor())
            ->patchJson('/calendar/events/'.$bar->id, [
                'title' => 'Moving Bar',
                'date' => self::DAY,
                'display_style' => Event::STYLE_PILL,
            ])
            ->assertOk();

        $this->assertSame(self::DAY, $bar->fresh()->date->format('Y-m-d'));
    }
}
