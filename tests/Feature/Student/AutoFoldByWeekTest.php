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
 * Sections fold themselves away once their week is over.
 *
 * The rule, for a user who has never touched the section:
 *
 *     scheduled_at before this week  -> collapsed
 *     scheduled_at during this week  -> open
 *     scheduled_at in the future     -> open   (staff-only view anyway)
 *     scheduled_at null              -> open   (no week to be old relative to)
 *
 * A stored preference always beats the rule, in both directions. Time is
 * frozen mid-week throughout so "this week" cannot drift under the assertions
 * — run these live on a Monday morning and a Sunday-scheduled section would
 * change sides.
 */
class AutoFoldByWeekTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday. Far enough from either edge that startOfWeek(Monday)
        // is unambiguous.
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

    private function section(?string $scheduledAt): Section
    {
        return Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => true,
            'scheduled_at' => $scheduledAt,
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

    /** Both of this week's days, exactly as asked for: 1/9 and 2/9 stay open. */
    public function test_every_section_scheduled_this_week_stays_open(): void
    {
        $monday = $this->section('2026-09-01 09:00:00');
        $wednesday = $this->section('2026-09-02 09:00:00');

        $collapsed = $this->collapsedOnPage();

        $this->assertNotContains($monday->id, $collapsed);
        $this->assertNotContains($wednesday->id, $collapsed);
    }

    public function test_last_weeks_section_starts_collapsed(): void
    {
        $lastWeek = $this->section('2026-08-28 09:00:00');

        $this->assertContains($lastWeek->id, $this->collapsedOnPage());
    }

    /**
     * Sunday the 30th and Monday the 31st are one day apart and land on
     * opposite sides of the boundary. This is the assertion that fails if the
     * week ever starts on Sunday.
     */
    public function test_the_week_boundary_is_monday(): void
    {
        $sunday = $this->section('2026-08-30 23:00:00');
        $monday = $this->section('2026-08-31 00:30:00');

        $collapsed = $this->collapsedOnPage();

        $this->assertContains($sunday->id, $collapsed, 'Sunday belongs to the week that just ended.');
        $this->assertNotContains($monday->id, $collapsed, 'Monday starts the current week.');
    }

    public function test_a_section_with_no_schedule_stays_open(): void
    {
        $unscheduled = $this->section(null);

        $this->assertNotContains($unscheduled->id, $this->collapsedOnPage());
    }

    /**
     * A future section is not the student's to see, folded or otherwise.
     *
     * isVisibleToStudents() returns scheduled_at->isPast(), so the section is
     * filtered out of the page entirely — the fold rule never gets a say for
     * this viewer, and asserting "it stays open" against a student would be
     * testing nothing.
     */
    public function test_a_student_never_sees_a_future_section_at_all(): void
    {
        $future = $this->section('2026-09-20 09:00:00');

        $html = $this->actingAs($this->student)
            ->get("/courses/{$this->course->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(e($future->title), $html);
    }

    /**
     * Staff do see it, and for them it stays open — hiding next week's prep
     * from the person who scheduled it helps nobody. This is the only viewer
     * for whom the future branch of the rule is reachable.
     */
    public function test_a_future_section_stays_open_for_staff(): void
    {
        $future = $this->section('2026-09-20 09:00:00');

        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $response = $this->actingAs($teacher)
            ->get("/courses/{$this->course->slug}")
            ->assertOk();

        $this->assertStringContainsString(e($future->title), $response->getContent(),
            'Staff should see the unreleased section.');
        $this->assertNotContains($future->id, $response->viewData('collapsedSectionIds'));
    }

    // ---- The override -------------------------------------------------------

    public function test_an_explicit_open_beats_the_rule(): void
    {
        $lastWeek = $this->section('2026-08-28 09:00:00');
        DB::table('user_collapsed_sections')->insert([
            'user_id' => $this->student->id, 'section_id' => $lastWeek->id,
            'collapsed' => false, 'created_at' => now(),
        ]);

        $this->assertNotContains($lastWeek->id, $this->collapsedOnPage(),
            'The user opened this one; the date rule must not close it again.');
    }

    public function test_an_explicit_collapse_beats_the_rule(): void
    {
        $thisWeek = $this->section('2026-09-02 09:00:00');
        DB::table('user_collapsed_sections')->insert([
            'user_id' => $this->student->id, 'section_id' => $thisWeek->id,
            'collapsed' => true, 'created_at' => now(),
        ]);

        $this->assertContains($thisWeek->id, $this->collapsedOnPage());
    }

    /** One user's preference is not another's. */
    public function test_the_override_is_per_user(): void
    {
        $lastWeek = $this->section('2026-08-28 09:00:00');
        DB::table('user_collapsed_sections')->insert([
            'user_id' => $this->student->id, 'section_id' => $lastWeek->id,
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

        $this->assertContains($lastWeek->id, $collapsed,
            'The other student never opened it, so the rule still applies to them.');
    }

    // ---- Toggling out of the automatic state --------------------------------

    /**
     * Clicking an auto-collapsed header must open it.
     *
     * The old toggle inserted a collapsed row whenever none existed, which
     * against a section the rule had already folded would have stored
     * "collapsed" and left the section shut.
     */
    public function test_clicking_an_auto_collapsed_section_opens_it(): void
    {
        $lastWeek = $this->section('2026-08-28 09:00:00');

        $this->actingAs($this->student)
            ->postJson("/sections/{$lastWeek->id}/toggle-fold")
            ->assertOk()
            ->assertJson(['collapsed' => false]);

        $this->assertNotContains($lastWeek->id, $this->collapsedOnPage());
    }

    /** And the choice survives the next visit rather than snapping back. */
    public function test_that_choice_persists(): void
    {
        $lastWeek = $this->section('2026-08-28 09:00:00');

        $this->actingAs($this->student)
            ->postJson("/sections/{$lastWeek->id}/toggle-fold")
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->assertNotContains($lastWeek->id, $this->collapsedOnPage());
    }

    /** Clicking an auto-open section still closes it. */
    public function test_clicking_an_auto_open_section_closes_it(): void
    {
        $thisWeek = $this->section('2026-09-02 09:00:00');

        $this->actingAs($this->student)
            ->postJson("/sections/{$thisWeek->id}/toggle-fold")
            ->assertOk()
            ->assertJson(['collapsed' => true]);

        $this->assertContains($thisWeek->id, $this->collapsedOnPage());
    }

    /**
     * Expand all does not survive a reload, and is not meant to.
     *
     * It is a look-at-everything control, not a preference: it moves the DOM
     * and writes nothing, so last week's sections are folded again on the next
     * visit. See FoldAllSectionsTest.
     */
    public function test_the_rule_still_applies_after_a_reload(): void
    {
        $a = $this->section('2026-08-28 09:00:00');
        $b = $this->section('2026-08-20 09:00:00');

        $collapsed = $this->collapsedOnPage();

        $this->assertContains($a->id, $collapsed);
        $this->assertContains($b->id, $collapsed);
        // Nothing is written by simply viewing the page.
        $this->assertDatabaseCount('user_collapsed_sections', 0);
    }
}
