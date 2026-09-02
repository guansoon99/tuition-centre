<?php

namespace Tests\Feature\Teacher;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * When `published_at` gets stamped.
 *
 * It is the clock the fold rule runs on — a section stays expanded for one
 * week from this moment (Section::startsCollapsedByDefault()) — so anything
 * that sets it wrongly makes sections pop open or vanish for every student at
 * once. There are exactly three ways a section becomes published, and each is
 * covered here, along with the ways it must NOT move.
 */
class SectionPublishedAtTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-02 10:00:00'));

        $this->seed(RolesAndPermissionsSeeder::class);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web'])
            ->givePermissionTo(['sections.manage', 'courses.view']);

        $this->course = Course::factory()->create(['is_active' => true]);

        $this->teacher = User::factory()->create(['is_active' => true]);
        $this->teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- Creating -----------------------------------------------------------

    public function test_creating_a_published_section_stamps_it(): void
    {
        $this->actingAs($this->teacher)
            ->post(route('sections.store', $this->course), [
                'title' => 'Minggu 1',
                'is_published' => true,
            ])
            ->assertRedirect();

        $section = Section::where('title', 'Minggu 1')->firstOrFail();

        $this->assertNotNull($section->published_at);
        $this->assertTrue($section->published_at->equalTo(now()));
    }

    public function test_creating_a_draft_section_leaves_it_null(): void
    {
        $this->actingAs($this->teacher)
            ->post(route('sections.store', $this->course), [
                'title' => 'Draft',
                'is_published' => false,
            ])
            ->assertRedirect();

        $this->assertNull(Section::where('title', 'Draft')->firstOrFail()->published_at);
    }

    // ---- Publishing and unpublishing ----------------------------------------

    public function test_publishing_a_draft_stamps_it(): void
    {
        $section = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => false,
            'published_at' => null,
            'scheduled_at' => null,
        ]);

        $this->actingAs($this->teacher)
            ->patch(route('sections.update', $section), [
                'title' => $section->title,
                'is_published' => true,
            ])
            ->assertRedirect();

        $this->assertTrue($section->fresh()->published_at->equalTo(now()));
    }

    public function test_unpublishing_clears_it(): void
    {
        $section = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => true,
            'published_at' => now()->subDays(3),
            'scheduled_at' => null,
        ]);

        $this->actingAs($this->teacher)
            ->patch(route('sections.update', $section), [
                'title' => $section->title,
                'is_published' => false,
            ])
            ->assertRedirect();

        $this->assertNull($section->fresh()->published_at);
    }

    /**
     * The one that would bite hardest.
     *
     * Editing a title on a section published a month ago must not restart its
     * week — that would silently pop a long-folded section back open for every
     * student on the course.
     */
    public function test_editing_an_already_published_section_does_not_restart_its_week(): void
    {
        $publishedAt = Carbon::parse('2026-08-01 09:00:00');

        $section = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => true,
            'published_at' => $publishedAt,
            'scheduled_at' => null,
        ]);

        $this->actingAs($this->teacher)
            ->patch(route('sections.update', $section), [
                'title' => 'Renamed',
                'is_published' => true,
            ])
            ->assertRedirect();

        $fresh = $section->fresh();

        $this->assertSame('Renamed', $fresh->title);
        $this->assertTrue($fresh->published_at->equalTo($publishedAt),
            'published_at must survive an ordinary edit untouched.');
        $this->assertTrue($fresh->startsCollapsedByDefault(),
            'And the section must stay folded.');
    }

    // ---- Available-from wins over the tick box ------------------------------

    /**
     * Ticking "published" while setting a future available-from.
     *
     * The two are not the same switch: isVisibleToStudents() answers from
     * scheduled_at alone whenever it is set, so the section stays hidden
     * until that date no matter what the box says. published_at therefore has
     * to be the date, not the moment of the click — otherwise the expanded
     * week is measured from a point when nobody could see the section.
     */
    public function test_publishing_with_a_future_available_from_dates_it_from_that_day(): void
    {
        $this->actingAs($this->teacher)
            ->post(route('sections.store', $this->course), [
                'title' => 'Next week',
                'scheduled_at' => '2026-09-09 00:00:00',
                'is_published' => true,
            ])
            ->assertRedirect();

        $section = Section::where('title', 'Next week')->firstOrFail();

        $this->assertTrue($section->published_at->equalTo(Carbon::parse('2026-09-09 00:00:00')));
        $this->assertFalse($section->isVisibleToStudents(), 'Hidden until the date, box ticked or not.');
    }

    /**
     * The bug this pair of tests exists for.
     *
     * Dated a month out and stamped with now(), the section's week ran out
     * three weeks before any student could see it — so it appeared already
     * folded on the day it went live, which is the exact opposite of the
     * feature. It must arrive open.
     */
    public function test_a_far_future_section_is_open_on_the_day_it_appears(): void
    {
        $this->actingAs($this->teacher)
            ->post(route('sections.store', $this->course), [
                'title' => 'End of month',
                'scheduled_at' => '2026-09-30 00:00:00',
                'is_published' => true,
            ])
            ->assertRedirect();

        $section = Section::where('title', 'End of month')->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00'));

        $this->assertTrue($section->isVisibleToStudents());
        $this->assertFalse($section->startsCollapsedByDefault(),
            'It should arrive open, not already folded.');

        Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));
        $this->assertTrue($section->startsCollapsedByDefault(),
            'And fold a week after it appeared, not a week after it was typed.');
    }

    /** Moving the available-from date moves the week with it. */
    public function test_editing_the_available_from_date_moves_published_at(): void
    {
        $section = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => true,
            'scheduled_at' => '2026-09-05 00:00:00',
            'published_at' => '2026-09-05 00:00:00',
        ]);

        $this->actingAs($this->teacher)
            ->patch(route('sections.update', $section), [
                'title' => $section->title,
                'scheduled_at' => '2026-09-20 00:00:00',
                'is_published' => true,
            ])
            ->assertRedirect();

        $this->assertTrue($section->fresh()->published_at->equalTo(Carbon::parse('2026-09-20 00:00:00')));
    }

    /** Clearing the date leaves the original publication moment alone. */
    public function test_clearing_the_available_from_date_keeps_the_existing_stamp(): void
    {
        $section = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => true,
            'scheduled_at' => '2026-08-20 00:00:00',
            'published_at' => '2026-08-20 00:00:00',
        ]);

        $this->actingAs($this->teacher)
            ->patch(route('sections.update', $section), [
                'title' => $section->title,
                'scheduled_at' => '',
                'is_published' => true,
            ])
            ->assertRedirect();

        $fresh = $section->fresh();

        $this->assertNull($fresh->scheduled_at);
        $this->assertTrue($fresh->published_at->equalTo(Carbon::parse('2026-08-20 00:00:00')),
            'It has been visible since the 20th; clearing the gate does not make it new.');
    }

    // ---- The always-open flag -----------------------------------------------

    public function test_the_always_open_flag_saves_from_the_form(): void
    {
        $this->actingAs($this->teacher)
            ->post(route('sections.store', $this->course), [
                'title' => 'Pengumuman',
                'is_published' => true,
                'never_collapses' => '1',
            ])
            ->assertRedirect();

        $section = Section::where('title', 'Pengumuman')->firstOrFail();

        $this->assertTrue($section->never_collapses);
        $this->assertFalse($section->startsCollapsedByDefault());
    }

    /**
     * Unticking has to arrive as false, not as a missing field.
     *
     * Both section forms send a hidden 0 alongside the checkbox for exactly
     * this reason — an unticked box submits nothing on its own, and the
     * section would keep the flag forever.
     */
    public function test_unticking_always_open_turns_it_off(): void
    {
        $section = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => true,
            'scheduled_at' => null,
            'published_at' => '2026-08-01 09:00:00',
            'never_collapses' => true,
        ]);

        $this->actingAs($this->teacher)
            ->patch(route('sections.update', $section), [
                'title' => $section->title,
                'is_published' => true,
                'never_collapses' => '0',
            ])
            ->assertRedirect();

        $fresh = $section->fresh();

        $this->assertFalse($fresh->never_collapses);
        $this->assertTrue($fresh->startsCollapsedByDefault(), 'It folds again once unpinned.');
    }

    /**
     * "Always open" must ship the hidden 0 next to its checkbox.
     *
     * An unticked checkbox submits nothing at all, so without the hidden
     * companion the field would be absent from the request entirely and
     * there would be no way to turn the flag back off.
     *
     * Status does not need one — it is a select, and a select always submits
     * whichever option is showing.
     */
    public function test_both_section_forms_render_the_always_open_checkbox_with_its_fallback(): void
    {
        $section = $this->publishedSection();

        $hidden = 'type="hidden" name="never_collapses" value="0"';
        $checkbox = 'type="checkbox" name="never_collapses" value="1"';

        foreach ($this->bothForms($section) as $where => $html) {
            $this->assertStringContainsString($hidden, $html, $where);
            $this->assertStringContainsString($checkbox, $html, $where);
        }
    }

    // ---- Status is a select, not a checkbox ---------------------------------

    public function test_both_section_forms_render_status_as_a_select(): void
    {
        $section = $this->publishedSection();

        foreach ($this->bothForms($section) as $where => $html) {
            $this->assertStringContainsString('<select name="is_published"', $html, $where);
            $this->assertStringContainsString('>Published</option>', $html, $where);
            $this->assertStringContainsString('>Unpublished</option>', $html, $where);
            $this->assertStringNotContainsString('type="checkbox" name="is_published"', $html, $where);
        }
    }

    /**
     * The select must open on the section's current state.
     *
     * Getting this wrong is silent and destructive: the form would show
     * "Published" for a draft, and saving anything at all would publish it.
     */
    public function test_the_status_select_preselects_the_current_state(): void
    {
        $draft = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => false,
            'scheduled_at' => null,
            'published_at' => null,
        ]);

        foreach ($this->bothForms($draft) as $where => $html) {
            $this->assertMatchesRegularExpression(
                '/<option value="0"[^>]*selected[^>]*>Unpublished<\/option>/', $html, $where);
            $this->assertDoesNotMatchRegularExpression(
                '/<option value="1"[^>]*selected[^>]*>Published<\/option>/', $html, $where);
        }
    }

    /** Saving Unpublished from the select still unpublishes. */
    public function test_selecting_unpublished_takes_the_section_down(): void
    {
        $section = $this->publishedSection();

        $this->actingAs($this->teacher)
            ->patch(route('sections.update', $section), [
                'title' => $section->title,
                'is_published' => '0',
            ])
            ->assertRedirect();

        $fresh = $section->fresh();

        $this->assertFalse($fresh->is_published);
        $this->assertNull($fresh->published_at);
    }

    private function publishedSection(): Section
    {
        return Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => true,
            'scheduled_at' => null,
            'published_at' => now(),
        ]);
    }

    /** @return array<string, string> the same section's markup in both forms */
    private function bothForms(Section $section): array
    {
        return [
            'standalone edit page' => $this->actingAs($this->teacher)
                ->get(route('sections.edit', $section))->assertOk()->getContent(),
            'materials tab modal' => $this->actingAs($this->teacher)
                ->get(route('courses.edit', $this->course).'?tab=materials')->assertOk()->getContent(),
        ];
    }

    /** Sections default to folding; the flag is opt-in. */
    public function test_a_new_section_is_not_always_open_by_default(): void
    {
        $this->actingAs($this->teacher)
            ->post(route('sections.store', $this->course), [
                'title' => 'Ordinary',
                'is_published' => true,
            ])
            ->assertRedirect();

        $this->assertFalse(Section::where('title', 'Ordinary')->firstOrFail()->never_collapses);
    }

    // ---- Scheduled release --------------------------------------------------

    /**
     * A scheduled section is released lazily, on the first course-page load
     * after its time passes. That load can be days late, so the stamp is the
     * scheduled moment rather than now() — otherwise a section overdue by a
     * month would be handed a fresh week of being expanded.
     */
    public function test_a_scheduled_release_is_dated_by_the_schedule_not_the_page_load(): void
    {
        $scheduledAt = Carbon::parse('2026-08-05 12:00:00');

        $section = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => false,
            'published_at' => null,
            'scheduled_at' => $scheduledAt,
        ]);

        // Viewing the course is what triggers the release.
        $this->actingAs($this->teacher)
            ->get("/courses/{$this->course->slug}")
            ->assertOk();

        $fresh = $section->fresh();

        $this->assertTrue($fresh->is_published);
        $this->assertTrue($fresh->published_at->equalTo($scheduledAt),
            'A late release must not reset the clock to now().');
        $this->assertTrue($fresh->startsCollapsedByDefault(),
            'Released nearly a month ago, so it should already be folded.');
    }

    /** A section released on time is inside its week and stays open. */
    public function test_a_freshly_due_section_is_open(): void
    {
        $section = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => false,
            'published_at' => null,
            'scheduled_at' => now()->subHour(),
        ]);

        $this->actingAs($this->teacher)
            ->get("/courses/{$this->course->slug}")
            ->assertOk();

        $this->assertFalse($section->fresh()->startsCollapsedByDefault());
    }
}
