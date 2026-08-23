<?php

namespace Tests\Feature\Admin;

use App\Models\Event;
use App\Models\User;
use App\Support\HolidayProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Creating an event on a public-holiday date.
 *
 * Holidays arrive from the Google iCal feed as *background* events, and the
 * calendar's dateClick handler used to open any background event it found on
 * the clicked day and stop there. Because a holiday is a background event,
 * that made holiday dates impossible to add anything to — you could not put a
 * highlight on Merdeka.
 *
 * Nothing on the server ever refused it, so the fix is client-side. What these
 * tests pin is the data the fixed handler branches on: that the two kinds of
 * background event are distinguishable, and that both can coexist on one date.
 */
class CalendarHolidayEventTest extends TestCase
{
    use RefreshDatabase;

    private const MERDEKA = '2026-08-31';

    private function creator(): User
    {
        $role = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $role->givePermissionTo('calendar.create');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** Keeps the iCal feed out of the test entirely. */
    private function fakeHoliday(): void
    {
        $this->mock(HolidayProvider::class, function ($mock) {
            $mock->shouldReceive('forRange')->andReturn([
                ['date' => self::MERDEKA, 'name' => 'Merdeka Day'],
            ]);
        });
    }

    private function feed(User $as): array
    {
        return $this->actingAs($as)
            ->getJson('/calendar/events?start='.self::MERDEKA.'T00:00:00&end=2026-09-30T00:00:00')
            ->assertOk()
            ->json();
    }

    // ---- The discriminator the handler relies on ----------------------------

    /**
     * A holiday is flagged; a user event is not. The click handler tells them
     * apart on exactly this, so if the flag ever stopped being sent, holiday
     * dates would silently become unclickable again.
     */
    public function test_only_the_holiday_carries_the_is_holiday_flag(): void
    {
        $this->fakeHoliday();
        $user = $this->creator();

        Event::create([
            'title' => 'Sports Day',
            'date' => self::MERDEKA,
            'color' => 'blue',
            'display_style' => Event::STYLE_BACKGROUND,
            'created_by_user_id' => $user->id,
        ]);

        $feed = collect($this->feed($user))->where('start', self::MERDEKA);

        $holiday = $feed->firstWhere('title', 'Merdeka Day');
        $mine = $feed->firstWhere('title', 'Sports Day');

        $this->assertNotNull($holiday, 'The holiday should be in the feed.');
        $this->assertNotNull($mine, 'And so should the event created on the same day.');

        $this->assertTrue($holiday['extendedProps']['isHoliday'] ?? false);
        $this->assertArrayNotHasKey('isHoliday', $mine['extendedProps']);
    }

    /** Both are background events — which is what caused the collision. */
    public function test_a_highlight_and_a_holiday_are_both_background_events(): void
    {
        $this->fakeHoliday();
        $user = $this->creator();

        Event::create([
            'title' => 'Sports Day',
            'date' => self::MERDEKA,
            'color' => 'blue',
            'display_style' => Event::STYLE_BACKGROUND,
            'created_by_user_id' => $user->id,
        ]);

        $onTheDay = collect($this->feed($user))->where('start', self::MERDEKA);

        $this->assertCount(2, $onTheDay, 'Both should sit on the same date.');
        foreach ($onTheDay as $event) {
            $this->assertSame('background', $event['display']);
        }
    }

    // ---- The colour the date number is painted with -------------------------

    /**
     * A holiday's red date number comes from CSS, but a user highlight's colour
     * is per-event, so the frontend paints the number inline from this value.
     * If the feed stopped sending it, highlighted days would keep the default
     * slate number and the chosen colour would only show as the cell wash.
     */
    public function test_the_feed_sends_the_text_colour_for_a_user_highlight(): void
    {
        $this->fakeHoliday();
        $user = $this->creator();

        Event::create([
            'title' => 'Green Highlight',
            'date' => self::MERDEKA,
            'color' => 'green',
            'display_style' => Event::STYLE_BACKGROUND,
            'created_by_user_id' => $user->id,
        ]);

        $mine = collect($this->feed($user))->firstWhere('title', 'Green Highlight');

        $this->assertSame(Event::COLOR_TEXTS['green'], $mine['extendedProps']['textHex']);
        $this->assertNotSame(
            Event::COLOR_TEXTS['red'],
            $mine['extendedProps']['textHex'],
            'A green highlight must not inherit the holiday red.',
        );
    }

    /**
     * A holiday and a hand-made red highlight must be the same red.
     *
     * They were not: the day number for a holiday came from a hardcoded
     * red-600 in the stylesheet, while a user's red came from the palette
     * (red-700). Both now read the same field, and this fails if either side
     * starts sourcing its colour somewhere else.
     */
    public function test_a_holiday_and_a_manual_red_highlight_use_the_same_red(): void
    {
        $this->fakeHoliday();
        $user = $this->creator();

        Event::create([
            'title' => 'Manual Red',
            'date' => '2026-09-02',
            'color' => 'red',
            'display_style' => Event::STYLE_BACKGROUND,
            'created_by_user_id' => $user->id,
        ]);

        $feed = collect($this->feed($user));
        $holiday = $feed->firstWhere('title', 'Merdeka Day');
        $manual = $feed->firstWhere('title', 'Manual Red');

        $this->assertSame(
            $holiday['extendedProps']['textHex'],
            $manual['extendedProps']['textHex'],
            'The date number would show two different reds.',
        );
        $this->assertSame(
            $holiday['backgroundColor'],
            $manual['backgroundColor'],
            'And the cell wash would differ too.',
        );
        $this->assertSame(Event::COLOR_TEXTS['red'], $manual['extendedProps']['textHex']);
    }

    /** Presence check on the inline painting — Alpine, so not run by PHPUnit. */
    public function test_the_day_number_is_painted_from_the_events_own_colour(): void
    {
        $html = $this->actingAs($this->creator())->get('/calendar')->assertOk()->getContent();

        $this->assertStringContainsString('.fc-daygrid-day-number', $html);
        $this->assertStringContainsString(
            "dayNumber.style.setProperty('color', info.event.extendedProps.textHex)",
            $html,
        );
    }

    // ---- The server never objected ------------------------------------------

    public function test_a_highlight_can_be_created_on_a_holiday_date(): void
    {
        $this->fakeHoliday();

        $this->actingAs($this->creator())
            ->postJson('/calendar/events', [
                'title' => 'Merdeka Highlight',
                'date' => self::MERDEKA,
                'color' => 'green',
                'display_style' => Event::STYLE_BACKGROUND,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $created = Event::where('title', 'Merdeka Highlight')->firstOrFail();

        $this->assertSame(self::MERDEKA, $created->date->format('Y-m-d'));
        $this->assertSame(Event::STYLE_BACKGROUND, $created->display_style);
    }

    /** A bar-style event on a holiday date was never blocked either. */
    public function test_a_bar_event_can_also_be_created_on_a_holiday_date(): void
    {
        $this->fakeHoliday();

        $this->actingAs($this->creator())
            ->postJson('/calendar/events', [
                'title' => 'Merdeka Bar',
                'date' => self::MERDEKA,
                'display_style' => Event::STYLE_PILL,
            ])
            ->assertOk();

        $this->assertSame(
            Event::STYLE_PILL,
            Event::where('title', 'Merdeka Bar')->value('display_style'),
        );
    }

    // ---- The handler itself -------------------------------------------------

    /**
     * A presence check on the client-side fix — PHPUnit does not run the
     * Alpine, so this only catches the guard being deleted, not its behaviour.
     */
    public function test_the_click_handler_still_prefers_a_user_highlight_over_a_holiday(): void
    {
        $html = $this->actingAs($this->creator())->get('/calendar')->assertOk()->getContent();

        $this->assertStringContainsString(
            'bgEvents.find(e => ! e.extendedProps?.isHoliday)',
            $html,
            'The handler must still separate user highlights from holidays.',
        );
    }
}
