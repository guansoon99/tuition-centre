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
 * Expand all / collapse all.
 *
 * One request for the whole course rather than one per section: a full
 * school-year course runs to thirty sections, and thirty POSTs at a
 * single-threaded server would queue behind each other.
 *
 * The section list is derived server-side. The page shows staff their
 * unpublished sections and students only the visible ones, so accepting ids
 * from the browser would let a student collapse — and store rows for —
 * sections they cannot see.
 */
class FoldAllSectionsTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $student;

    /** @var array<int> visible to students */
    private array $publishedIds = [];

    private Section $draftSection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web'])
            ->givePermissionTo('sections.manage');

        $this->course = Course::factory()->create(['is_active' => true]);

        foreach (range(1, 3) as $i) {
            $this->publishedIds[] = Section::factory()->create([
                'course_id' => $this->course->id, 'is_published' => true, 'scheduled_at' => null,
            ])->id;
        }

        // Not visible to students; staff still see it.
        $this->draftSection = Section::factory()->create([
            'course_id' => $this->course->id, 'is_published' => false, 'scheduled_at' => null,
        ]);

        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    /** Section ids this user is storing an explicit *collapsed* against. */
    private function collapsedFor(User $user): array
    {
        return DB::table('user_collapsed_sections')
            ->where('user_id', $user->id)
            ->where('collapsed', true)
            ->pluck('section_id')
            ->sort()
            ->values()
            ->all();
    }

    private function fold(User $user, bool $collapsed)
    {
        return $this->actingAs($user)
            ->postJson(route('courses.fold-sections', $this->course), ['collapsed' => $collapsed]);
    }

    // ---- Collapsing ---------------------------------------------------------

    public function test_collapse_all_stores_a_row_per_visible_section(): void
    {
        $this->fold($this->student, true)->assertOk();

        $this->assertSame($this->publishedIds, $this->collapsedFor($this->student));
    }

    /** The draft section is not the student's to collapse. */
    public function test_collapse_all_skips_sections_the_student_cannot_see(): void
    {
        $this->fold($this->student, true)->assertOk();

        $this->assertNotContains($this->draftSection->id, $this->collapsedFor($this->student));
    }

    /**
     * Running it twice must not fail.
     *
     * unique(user_id, section_id) means a plain insert would throw on the
     * second call and lose the whole batch — hence upsert.
     */
    public function test_collapse_all_is_repeatable(): void
    {
        $this->fold($this->student, true)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->fold($this->student, true)->assertOk();

        $this->assertSame($this->publishedIds, $this->collapsedFor($this->student));
    }

    /** A part-collapsed course collapses the rest without duplicating. */
    public function test_collapse_all_from_a_partly_collapsed_state(): void
    {
        DB::table('user_collapsed_sections')->insert([
            'user_id' => $this->student->id,
            'section_id' => $this->publishedIds[0],
            'collapsed' => true,
            'created_at' => now(),
        ]);

        $this->fold($this->student, true)->assertOk();

        $this->assertSame($this->publishedIds, $this->collapsedFor($this->student));
    }

    // ---- Expanding ----------------------------------------------------------

    public function test_expand_all_leaves_nothing_collapsed(): void
    {
        $this->fold($this->student, true)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->fold($this->student, false)->assertOk();

        $this->assertSame([], $this->collapsedFor($this->student));
    }

    /**
     * Expanding writes explicit open rows rather than deleting.
     *
     * Deleting would return the sections to the date rule, and a past week's
     * would collapse again on the very next load — the button would look
     * broken. See CourseController::foldAll().
     */
    public function test_expand_all_records_the_choice_rather_than_forgetting_it(): void
    {
        $this->fold($this->student, false)->assertOk();

        $rows = DB::table('user_collapsed_sections')
            ->where('user_id', $this->student->id)
            ->pluck('collapsed', 'section_id');

        foreach ($this->publishedIds as $id) {
            $this->assertTrue($rows->has($id), "Section {$id} should have a stored preference.");
            $this->assertFalse((bool) $rows[$id], "Section {$id} should be stored as open.");
        }
    }

    /** Another course's fold state is untouched. */
    public function test_expand_all_only_clears_this_course(): void
    {
        $otherCourse = Course::factory()->create(['is_active' => true]);
        $otherSection = Section::factory()->create([
            'course_id' => $otherCourse->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        DB::table('user_collapsed_sections')->insert([
            'user_id' => $this->student->id,
            'section_id' => $otherSection->id,
            'collapsed' => true,
            'created_at' => now(),
        ]);

        $this->fold($this->student, true)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->fold($this->student, false)->assertOk();

        $this->assertSame([$otherSection->id], $this->collapsedFor($this->student),
            "The other course's collapsed section should survive.");
    }

    // ---- Scope --------------------------------------------------------------

    /** Staff see drafts on the page, so collapse all covers them too. */
    public function test_a_teacher_collapses_their_draft_sections_as_well(): void
    {
        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        $this->fold($teacher, true)->assertOk();

        $this->assertContains($this->draftSection->id, $this->collapsedFor($teacher));
    }

    public function test_a_stranger_cannot_fold_a_course_they_are_not_in(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('student');

        $this->fold($outsider, true)->assertForbidden();

        $this->assertSame([], $this->collapsedFor($outsider));
    }

    // ---- The page -----------------------------------------------------------

    /**
     * Both buttons, always, side by side.
     *
     * They were briefly hidden when their action would do nothing, which left
     * exactly one on screen at any moment — reading as a single button that
     * changes its mind rather than two you can choose between.
     */
    public function test_both_buttons_appear_when_there_is_more_than_one_section(): void
    {
        $html = $this->actingAs($this->student)
            ->get("/courses/{$this->course->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Expand all', $html);
        $this->assertStringContainsString('Collapse all', $html);
        $this->assertStringNotContainsString('x-show="collapsedIds.length', $html,
            'Neither button should be conditionally hidden.');
    }

    /** Both stay put with everything already collapsed. */
    public function test_both_buttons_remain_when_every_section_is_collapsed(): void
    {
        $this->fold($this->student, true)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $html = $this->actingAs($this->student)
            ->get("/courses/{$this->course->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Expand all', $html);
        $this->assertStringContainsString('Collapse all', $html);
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
}
