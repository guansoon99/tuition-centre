<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sections scheduled for a future time publish themselves on the next view.
 *
 * There is no cron for this — the check on page load is the entire feature —
 * so it runs on every course view. It used to issue an UPDATE every time,
 * including for the overwhelming majority of courses where nothing is ever
 * scheduled. The check now reads the sections already loaded for the page.
 *
 * These tests cover both halves: that a due section still publishes (and is
 * visible on the request that published it), and that a course with nothing
 * due performs no write at all.
 */
class ScheduledSectionReleaseTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->course = Course::factory()->create(['is_active' => true]);

        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    private function visit(): string
    {
        return $this->actingAs($this->student)
            ->get("/courses/{$this->course->slug}")
            ->assertOk()
            ->getContent();
    }

    /** Writes issued while rendering the page. */
    private function writeCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->visit();
        $writes = 0;
        foreach (DB::getQueryLog() as $q) {
            if (preg_match('/^\s*(update|insert)\s+/i', $q['query'])) {
                $writes++;
            }
        }
        DB::disableQueryLog();

        return $writes;
    }

    // ---- The feature still works --------------------------------------------

    public function test_a_section_whose_time_has_passed_is_published(): void
    {
        $section = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => false,
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->visit();

        $this->assertTrue($section->fresh()->is_published,
            'A section past its scheduled time should publish on the next view.');
    }

    /**
     * And it is visible on the request that published it.
     *
     * Not because of the in-memory sync, as it turns out:
     * Section::isVisibleToStudents() ignores is_published whenever
     * scheduled_at is set and answers from the date alone, so the section
     * would render even if the flag were never flipped. The test is kept
     * because "a due section appears immediately" is the behaviour students
     * actually depend on, whichever mechanism delivers it.
     */
    public function test_the_freshly_published_section_renders_on_that_same_request(): void
    {
        Section::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Week 5 Materials',
            'is_published' => false,
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->assertStringContainsString('Week 5 Materials', $this->visit());
    }

    public function test_a_section_scheduled_for_later_stays_hidden(): void
    {
        $section = Section::factory()->create([
            'course_id' => $this->course->id,
            'title' => 'Not Yet Visible',
            'is_published' => false,
            'scheduled_at' => now()->addWeek(),
        ]);

        $html = $this->visit();

        $this->assertFalse($section->fresh()->is_published);
        $this->assertStringNotContainsString('Not Yet Visible', $html);
    }

    // ---- And costs nothing when there is nothing to do ----------------------

    /** The point of the change: no scheduled sections, no publish write. */
    public function test_a_course_with_nothing_scheduled_issues_no_release_write(): void
    {
        Section::factory()->count(3)->create([
            'course_id' => $this->course->id,
            'is_published' => true,
            'scheduled_at' => null,
        ]);

        $this->visit(); // warm caches so the count reflects steady state

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->visit();
        $sectionUpdates = 0;
        foreach (DB::getQueryLog() as $q) {
            if (preg_match('/^\s*update\s+"?sections"?/i', $q['query'])) {
                $sectionUpdates++;
            }
        }
        DB::disableQueryLog();

        $this->assertSame(0, $sectionUpdates,
            'Nothing is scheduled, so the page should not write to sections.');
    }

    /** A future-dated section is also not due, so it must not write either. */
    public function test_a_future_section_issues_no_release_write(): void
    {
        Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => false,
            'scheduled_at' => now()->addWeek(),
        ]);

        $this->visit();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->visit();
        $sectionUpdates = 0;
        foreach (DB::getQueryLog() as $q) {
            if (preg_match('/^\s*update\s+"?sections"?/i', $q['query'])) {
                $sectionUpdates++;
            }
        }
        DB::disableQueryLog();

        $this->assertSame(0, $sectionUpdates);
    }

    /** Safe to call without the relation loaded — falls back to the query. */
    public function test_it_still_works_when_sections_are_not_loaded(): void
    {
        $section = Section::factory()->create([
            'course_id' => $this->course->id,
            'is_published' => false,
            'scheduled_at' => now()->subMinute(),
        ]);

        Course::findOrFail($this->course->id)->releaseDueSections();

        $this->assertTrue($section->fresh()->is_published);
    }
}
