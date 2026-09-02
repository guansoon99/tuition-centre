<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Expand all / collapse all.
 *
 * These two are a way to see the whole course at once, not a preference.
 * They move the DOM and stop there: nothing is written, no request is sent,
 * and the next page load starts fresh from the date rule plus whatever
 * individual sections the user has actually chosen.
 *
 * That makes them cheap in a way the persisted version was not. Persisting
 * meant a course of old sections had to store an explicit "open" against
 * every one of them, which took that user off the automatic rule for that
 * course permanently — one click, no way back.
 *
 * There is nothing server-side left to test, so what follows guards the two
 * things that would quietly undo the decision: the buttons disappearing, and
 * persistence creeping back in.
 */
class FoldAllSectionsTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->course = Course::factory()->create(['is_active' => true]);

        foreach (range(1, 3) as $i) {
            Section::factory()->create([
                'course_id' => $this->course->id, 'is_published' => true, 'scheduled_at' => null,
            ]);
        }

        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    private function page(): string
    {
        return $this->actingAs($this->student)
            ->get("/courses/{$this->course->slug}")
            ->assertOk()
            ->getContent();
    }

    // ---- The buttons --------------------------------------------------------

    /**
     * Both, always, side by side.
     *
     * They were briefly hidden when their action would do nothing, which left
     * exactly one on screen at any moment — reading as a single button that
     * changes its mind rather than two you can choose between.
     */
    public function test_both_buttons_appear_when_there_is_more_than_one_section(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('Expand all', $html);
        $this->assertStringContainsString('Collapse all', $html);
        $this->assertStringNotContainsString('x-show="collapsedIds.length', $html,
            'Neither button should be conditionally hidden.');
    }

    /** Nothing to expand or collapse against a single section. */
    public function test_the_buttons_are_absent_for_a_one_section_course(): void
    {
        $solo = Course::factory()->create(['is_active' => true]);
        Section::factory()->create([
            'course_id' => $solo->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        Enrollment::create([
            'course_id' => $solo->id, 'user_id' => $this->student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $html = $this->actingAs($this->student)
            ->get("/courses/{$solo->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Collapse all', $html);
    }

    // ---- They must stay unpersisted -----------------------------------------

    /**
     * foldAll() touches collapsedIds and nothing else.
     *
     * Reaching for this.post() inside it is exactly how persistence would
     * come back, so the rendered function body is asserted directly.
     */
    public function test_the_bulk_handler_sends_no_request(): void
    {
        $html = $this->page();

        $start = strpos($html, 'foldAll(collapsed) {');
        $this->assertNotFalse($start, 'The bulk handler should be on the page.');

        $body = substr($html, $start, strpos($html, '}', $start) - $start);

        $this->assertStringNotContainsString('post(', $body);
        $this->assertStringNotContainsString('fetch(', $body);
    }

    /**
     * The single toggle must send the state it just rendered.
     *
     * Dropping that payload puts the server back to flipping its own stored
     * value, which after a bulk button disagrees with the screen — the click
     * would then save the opposite of what the user did. The server half of
     * this is covered below; this is the half that lives in the template.
     */
    public function test_the_single_toggle_sends_the_state_it_rendered(): void
    {
        $html = $this->page();

        $start = strpos($html, 'toggle(id) {');
        $this->assertNotFalse($start, 'The per-section toggle should be on the page.');

        $body = substr($html, $start, strpos($html, '},', $start) - $start);

        $this->assertStringContainsString('toggle-fold', $body);
        $this->assertStringContainsString('{ collapsed }', $body,
            'The request must carry the rendered state, not ask for a flip.');
    }

    /** And no endpoint survives for it to call. */
    public function test_no_bulk_fold_route_exists(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('courses.fold-sections'));

        $this->actingAs($this->student)
            ->postJson("/courses/{$this->course->slug}/fold-sections", ['collapsed' => true])
            ->assertNotFound();

        $this->assertDatabaseCount('user_collapsed_sections', 0);
    }

    // ---- The per-section toggle still persists -------------------------------

    /**
     * The bulk buttons going quiet must not take the single toggle with them —
     * that one is a preference and still has to survive a reload.
     */
    public function test_a_single_section_toggle_is_still_saved(): void
    {
        $section = $this->course->sections()->first();

        $this->actingAs($this->student)
            ->postJson("/sections/{$section->id}/toggle-fold", ['collapsed' => true])
            ->assertOk()
            ->assertJson(['collapsed' => true]);

        $this->assertDatabaseHas('user_collapsed_sections', [
            'user_id' => $this->student->id,
            'section_id' => $section->id,
            'collapsed' => true,
        ]);
    }

    /**
     * The state the client rendered wins over the server's own idea.
     *
     * After Expand all the two disagree by design — the screen shows open,
     * the server still has a collapsed row. Clicking the header then means
     * "close this", and flipping the stored value would reopen it instead.
     */
    public function test_an_explicit_state_beats_flipping_the_stored_one(): void
    {
        $section = $this->course->sections()->first();

        DB::table('user_collapsed_sections')->insert([
            'user_id' => $this->student->id, 'section_id' => $section->id,
            'collapsed' => true, 'created_at' => now(),
        ]);

        // The client is looking at an expanded section and is closing it.
        $this->actingAs($this->student)
            ->postJson("/sections/{$section->id}/toggle-fold", ['collapsed' => true])
            ->assertOk()
            ->assertJson(['collapsed' => true]);

        $this->assertDatabaseHas('user_collapsed_sections', [
            'user_id' => $this->student->id,
            'section_id' => $section->id,
            'collapsed' => true,
        ]);
    }

    public function test_a_stranger_cannot_toggle_a_course_they_are_not_in(): void
    {
        $section = $this->course->sections()->first();

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('student');

        $this->actingAs($outsider)
            ->postJson("/sections/{$section->id}/toggle-fold", ['collapsed' => true])
            ->assertForbidden();

        $this->assertDatabaseCount('user_collapsed_sections', 0);
    }
}
