<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A section stays expanded for a week after it is published, then folds.
 *
 * The rule, for a user who has never touched the section:
 *
 *     published on this day or the 7 days before -> open
 *     published earlier than that                -> collapsed
 *     published_at null (not published)          -> open, staff-only anyway
 *
 * Whole days on both sides, so the time of day a section was published never
 * matters and the state only ever changes at midnight.
 *
 * It is keyed on published_at rather than scheduled_at because scheduling is
 * optional and most sections have none — a rule reading scheduled_at would
 * never fire for them.
 *
 * A stored preference always beats the rule, in both directions. Time is
 * frozen throughout so the boundary cannot drift under the assertions.
 */
class AutoFoldByWeekTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-02 10:00:00'));

        $this->seed(RolesAndPermissionsSeeder::class);
        // The seeder does not create this one.
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web'])
            ->givePermissionTo('sections.manage');

        $this->course = Course::factory()->create(['is_active' => true]);
        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function section(?string $publishedAt): Section
    {
        return Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => $publishedAt !== null,
            'scheduled_at' => null,
            'published_at' => $publishedAt,
        ]);
    }

    /** The ids the page will render folded. */
    private function collapsedOnPage(): array
    {
        return $this->actingAs($this->student)
            ->get("/courses/{$this->course->slug}")
            ->assertOk()
            ->viewData('collapsedSectionIds');
    }

    // ---- The rule -----------------------------------------------------------

    public function test_a_section_published_today_is_open(): void
    {
        $today = $this->section('2026-09-02 08:00:00');

        $this->assertNotContains($today->id, $this->collapsedOnPage());
    }

    public function test_a_section_published_six_days_ago_is_still_open(): void
    {
        $recent = $this->section('2026-08-27 10:00:00');

        $this->assertNotContains($recent->id, $this->collapsedOnPage());
    }

    public function test_a_section_published_two_weeks_ago_is_collapsed(): void
    {
        $old = $this->section('2026-08-19 10:00:00');

        $this->assertContains($old->id, $this->collapsedOnPage());
    }

    /**
     * The boundary itself, from both sides. Today is Wednesday 2 September.
     *
     * A section published on the 26th — seven days back — is open for the
     * whole of today; one published on the 25th has folded. This is the
     * assertion that fails if the window is ever widened or narrowed by a day.
     */
    public function test_the_boundary_falls_between_seven_and_eight_days(): void
    {
        $sevenDays = $this->section('2026-08-26 10:00:00');
        $eightDays = $this->section('2026-08-25 10:00:00');

        $collapsed = $this->collapsedOnPage();

        $this->assertNotContains($sevenDays->id, $collapsed, 'Seven days back is still open.');
        $this->assertContains($eightDays->id, $collapsed, 'Eight days back has folded.');
    }

    /**
     * Time of day is ignored entirely.
     *
     * An exact 7x24h window folded a section at whatever clock time it had
     * been published at, so it could be readable all morning and gone by
     * mid-afternoon. Two sections published at either end of the same day
     * must now behave identically all day.
     */
    public function test_the_time_of_day_a_section_was_published_does_not_matter(): void
    {
        $firstMinute = $this->section('2026-08-26 00:01:00');
        $lastMinute = $this->section('2026-08-26 23:59:00');

        $collapsed = $this->collapsedOnPage();

        $this->assertNotContains($firstMinute->id, $collapsed);
        $this->assertNotContains($lastMinute->id, $collapsed);
    }

    /**
     * And the fold happens overnight, not part-way through a day.
     *
     * The same section is open at the last minute of its final day and
     * collapsed at the first minute of the next.
     */
    public function test_the_state_changes_at_midnight(): void
    {
        $section = $this->section('2026-08-26 14:30:00');

        Carbon::setTestNow(Carbon::parse('2026-09-02 23:59:00'));
        $this->assertNotContains($section->id, $this->collapsedOnPage(),
            'Still open at the very end of its last day.');

        Carbon::setTestNow(Carbon::parse('2026-09-03 00:01:00'));
        $this->assertContains($section->id, $this->collapsedOnPage(),
            'Folded a minute after midnight.');
    }

    /**
     * Publication date, not schedule date — the reason for the whole change.
     *
     * A section with no scheduled_at at all still folds once its week is up,
     * which the previous scheduled_at-based rule could never do.
     */
    public function test_an_unscheduled_section_still_folds_once_its_week_is_up(): void
    {
        $old = $this->section('2026-08-01 10:00:00');

        $this->assertNull($old->scheduled_at);
        $this->assertContains($old->id, $this->collapsedOnPage());
    }

    /** A draft has no publication date, and drafts are staff-only. */
    public function test_an_unpublished_section_stays_open_for_staff(): void
    {
        $draft = $this->section(null);

        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $response = $this->actingAs($teacher)
            ->get("/courses/{$this->course->slug}")
            ->assertOk();

        $this->assertNotContains($draft->id, $response->viewData('collapsedSectionIds'));
    }

    public function test_a_student_never_sees_an_unpublished_section(): void
    {
        $draft = $this->section(null);

        $html = $this->actingAs($this->student)
            ->get("/courses/{$this->course->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(e($draft->title), $html);
    }

    // ---- Opting out entirely ------------------------------------------------

    /**
     * A section marked "always open" ignores the clock completely.
     *
     * For standing content — an announcement, a countdown to an exam, a
     * coursework brief that matters all term — where folding after a week is
     * exactly wrong.
     */
    public function test_an_always_open_section_never_folds_however_old(): void
    {
        $ancient = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => true,
            'scheduled_at' => null,
            'published_at' => '2026-01-05 09:00:00',
            'never_collapses' => true,
        ]);

        $this->assertNotContains($ancient->id, $this->collapsedOnPage());
    }

    /** The flag only pins the section it is set on. */
    public function test_the_flag_does_not_leak_to_other_sections(): void
    {
        $pinned = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => true,
            'scheduled_at' => null,
            'published_at' => '2026-01-05 09:00:00',
            'never_collapses' => true,
        ]);
        $ordinary = $this->section('2026-01-05 09:00:00');

        $collapsed = $this->collapsedOnPage();

        $this->assertNotContains($pinned->id, $collapsed);
        $this->assertContains($ordinary->id, $collapsed);
    }

    /**
     * "Always open" is about the automatic rule, not about the student.
     *
     * They can still collapse it by hand, and that choice sticks — the flag
     * is a default, not a lock.
     */
    public function test_a_student_can_still_collapse_an_always_open_section(): void
    {
        $pinned = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => true,
            'scheduled_at' => null,
            'published_at' => '2026-01-05 09:00:00',
            'never_collapses' => true,
        ]);

        $this->actingAs($this->student)
            ->postJson("/sections/{$pinned->id}/toggle-fold", ['collapsed' => true])
            ->assertOk()
            ->assertJson(['collapsed' => true]);

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->assertContains($pinned->id, $this->collapsedOnPage(),
            'Their own choice still wins over the flag.');
    }

    // ---- The override -------------------------------------------------------

    public function test_an_explicit_open_beats_the_rule(): void
    {
        $old = $this->section('2026-08-01 10:00:00');
        DB::table('user_collapsed_sections')->insert([
            'user_id' => $this->student->id, 'section_id' => $old->id,
            'collapsed' => false, 'created_at' => now(),
        ]);

        $this->assertNotContains($old->id, $this->collapsedOnPage(),
            'The user opened this one; the rule must not close it again.');
    }

    public function test_an_explicit_collapse_beats_the_rule(): void
    {
        $fresh = $this->section('2026-09-02 08:00:00');
        DB::table('user_collapsed_sections')->insert([
            'user_id' => $this->student->id, 'section_id' => $fresh->id,
            'collapsed' => true, 'created_at' => now(),
        ]);

        $this->assertContains($fresh->id, $this->collapsedOnPage());
    }

    /** One user's preference is not another's. */
    public function test_the_override_is_per_user(): void
    {
        $old = $this->section('2026-08-01 10:00:00');
        DB::table('user_collapsed_sections')->insert([
            'user_id' => $this->student->id, 'section_id' => $old->id,
            'collapsed' => false, 'created_at' => now(),
        ]);

        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $other->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $collapsed = $this->actingAs($other)
            ->get("/courses/{$this->course->slug}")
            ->assertOk()
            ->viewData('collapsedSectionIds');

        $this->assertContains($old->id, $collapsed,
            'The other student never opened it, so the rule still applies to them.');
    }

    // ---- Toggling out of the automatic state --------------------------------

    /**
     * Clicking an auto-collapsed header must open it, and stay open.
     *
     * The click is the user forming an opinion, so a row is written and the
     * rule stops applying to that section for them.
     */
    public function test_clicking_an_auto_collapsed_section_opens_it_for_good(): void
    {
        $old = $this->section('2026-08-01 10:00:00');

        $this->actingAs($this->student)
            ->postJson("/sections/{$old->id}/toggle-fold", ['collapsed' => false])
            ->assertOk()
            ->assertJson(['collapsed' => false]);

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->assertNotContains($old->id, $this->collapsedOnPage());
    }

    /**
     * Expand all writes nothing, so the rule is back on the next visit.
     *
     * See FoldAllSectionsTest — the bulk buttons are a way to see everything
     * at once, not a preference.
     */
    public function test_the_rule_still_applies_after_a_reload(): void
    {
        $a = $this->section('2026-08-01 10:00:00');
        $b = $this->section('2026-08-10 10:00:00');

        $collapsed = $this->collapsedOnPage();

        $this->assertContains($a->id, $collapsed);
        $this->assertContains($b->id, $collapsed);

        // Nothing is written by simply viewing the page.
        $this->assertDatabaseCount('user_collapsed_sections', 0);
    }
}
