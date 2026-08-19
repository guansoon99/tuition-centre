<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An assignment's description, shown the way a page material is shown.
 *
 * It used to appear only as a squeezed preview in the course material list and
 * was not rendered at all once the assignment was opened — so anything past
 * the first line or two was invisible at exactly the moment a student was
 * about to submit. It now uses the same .prose-page styles as the "open as
 * separate page" view, which is why those styles moved into a shared partial:
 * two copies of the same rules is how the two views drift apart.
 */
class AssignmentDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = '<h2>Instructions</h2><p>Answer <strong style="color: rgb(230, 0, 0);">all</strong> questions.</p>';

    private Course $course;

    private Material $assignment;

    private User $student;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web'])
            ->givePermissionTo('sections.manage');

        $this->course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $this->course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        $this->assignment = Material::factory()->create([
            'section_id' => $section->id,
            'type' => Material::TYPE_ASSIGNMENT,
            'is_published' => true,
            'due_date' => now()->addWeek(),
            'body' => self::BODY,
        ]);

        $this->student = $this->enrol(Enrollment::ROLE_STUDENT, 'student');
        $this->teacher = $this->enrol(Enrollment::ROLE_TEACHER, 'teacher');
    }

    private function enrol(string $roleOnCourse, string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $user->id,
            'role_on_course' => $roleOnCourse, 'is_active' => true, 'enrolled_at' => now(),
        ]);

        return $user;
    }

    private function pageFor(User $as): string
    {
        return $this->actingAs($as)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->getContent();
    }

    /**
     * Just the description card.
     *
     * Assertions about the divider have to be scoped to it: the files card
     * further down the student page uses the same <hr> markup, so a page-wide
     * search finds that one instead and quietly passes or fails for the wrong
     * reason. Returns '' when no card is rendered at all.
     */
    private function cardFor(User $as): string
    {
        preg_match('/<article class="prose-page.*?<\/article>/s', $this->pageFor($as), $m);

        return $m[0] ?? '';
    }

    public function test_a_student_sees_the_description_on_the_assignment_page(): void
    {
        $html = $this->pageFor($this->student);

        $this->assertStringContainsString('<h2>Instructions</h2>', $html);
        $this->assertStringContainsString('Answer', $html);
    }

    public function test_a_teacher_sees_the_same_description(): void
    {
        $html = $this->pageFor($this->teacher);

        $this->assertStringContainsString('<h2>Instructions</h2>', $html);
    }

    /**
     * The point of the change: the same styles as the separate-page view, not
     * the condensed .prose-section preview used in the material list.
     */
    public function test_the_description_uses_the_page_prose_styles(): void
    {
        $html = $this->pageFor($this->student);

        $this->assertStringContainsString('class="prose-page', $html,
            'The description should carry the prose-page class.');
        $this->assertStringContainsString('.prose-page h1 {', $html,
            'The prose-page stylesheet did not reach the page.');
    }

    /** Formatting the teacher applied has to survive all the way to the student. */
    public function test_formatting_in_the_description_is_preserved(): void
    {
        $html = $this->pageFor($this->student);

        $this->assertStringContainsString('color: rgb(230, 0, 0)', $html,
            'Coloured text in the description was lost on the way to the page.');
        $this->assertStringContainsString('<strong', $html);
    }

    /** Both views carry the deadline, so the card survives an empty description. */
    public function test_the_teacher_card_keeps_the_deadline_without_a_description(): void
    {
        $this->assignment->update(['body' => '<p><br></p>']);

        $card = $this->cardFor($this->teacher);

        $this->assertNotSame('', $card);
        $this->assertStringContainsString('Due:', $card);
        $this->assertStringNotContainsString('<hr', $card, 'Nothing to divide, so no rule.');
    }

    /** With neither a deadline nor a description there is nothing to show. */
    public function test_no_card_at_all_without_a_deadline_or_description(): void
    {
        $this->assignment->update(['body' => null, 'due_date' => null]);

        $card = $this->cardFor($this->teacher);

        $this->assertStringNotContainsString('<h2>', $card);
        $this->assertStringContainsString('No due date.', $card);
    }

    /**
     * The student card also carries the deadline, so it survives an empty
     * description — but with nothing to separate, there is no rule.
     */
    public function test_the_student_card_keeps_the_deadline_without_a_description(): void
    {
        $this->assignment->update(['body' => null]);

        $card = $this->cardFor($this->student);

        $this->assertNotSame('', $card, 'The card should survive an empty description.');
        $this->assertStringContainsString('Due:', $card);
        $this->assertStringNotContainsString('<hr', $card, 'Nothing to divide, so no rule.');
    }

    /** The deadline sits above the description, divided from it. */
    public function test_the_student_card_shows_the_deadline_above_the_description(): void
    {
        $card = $this->cardFor($this->student);

        $due = strpos($card, 'Due:');
        $rule = strpos($card, '<hr');
        $body = strpos($card, '<h2>Instructions</h2>');

        $this->assertNotFalse($rule, 'The divider is missing.');
        $this->assertLessThan($rule, $due, 'The deadline should come before the divider.');
        $this->assertLessThan($body, $rule, 'The divider should come before the description.');
    }

    /** The title is a bare heading now, not wrapped in a card of its own. */
    public function test_the_student_title_is_a_bare_heading(): void
    {
        $html = $this->pageFor($this->student);

        $this->assertStringContainsString(
            '<h1 class="text-2xl font-semibold text-slate-900">',
            $html,
            'The title should match the separate-page view: a plain heading, no card.',
        );
    }

    /**
     * Both views render the title from one partial.
     *
     * The teacher's was a smaller heading inside a white card, so the same
     * assignment looked like a different page depending on who opened it.
     * Asserting on both is what stops them drifting apart again.
     */
    public function test_the_teacher_title_matches_the_student_title(): void
    {
        // One actor per test: a second actingAs after a request does not take
        // in this suite, and the resulting 302 reads as an authorisation
        // failure rather than a test artefact.
        $html = $this->pageFor($this->teacher);

        $this->assertStringContainsString(
            '<h1 class="text-2xl font-semibold text-slate-900">',
            $html,
            'The teacher view should use the shared title partial.',
        );
        $this->assertStringNotContainsString(
            '<h1 class="text-xl font-semibold text-slate-900">',
            $html,
            'The old in-card heading is still being rendered.',
        );
    }

    /** The title is above the description card, not inside any card. */
    public function test_the_teacher_title_sits_outside_the_card(): void
    {
        $html = $this->pageFor($this->teacher);

        $this->assertLessThan(
            strpos($html, 'class="prose-page'),
            strpos($html, '<h1 class="text-2xl'),
            'The heading should come before the description card.',
        );
    }

    /**
     * The countdown now appears under the deadline AND as a status row, but
     * both render the same partial — there is one implementation, so the two
     * cannot disagree about how long is left.
     */
    public function test_every_countdown_comes_from_the_shared_partial(): void
    {
        $html = $this->pageFor($this->student);

        $counters = substr_count($html, 'setInterval(() => tick(), 30000)');
        $labels = substr_count($html, 'Time remaining');

        $this->assertSame(2, $counters, 'Expected one under the deadline and one in the status table.');
        $this->assertSame($counters, $labels, 'Every counter should carry a label.');
    }

    /** The teacher has no status table, so exactly one there. */
    public function test_the_teacher_gets_the_countdown_under_the_deadline(): void
    {
        $card = $this->cardFor($this->teacher);

        $this->assertStringContainsString('Time remaining', $card);
        $this->assertSame(1, substr_count($this->pageFor($this->teacher), 'setInterval(() => tick(), 30000)'));
    }

    /** The card carries the deadline and how long is left, in that order. */
    public function test_the_description_card_shows_the_deadline_then_the_time_left(): void
    {
        $card = $this->cardFor($this->student);

        $due = strpos($card, 'Due:');
        $remaining = strpos($card, 'Time remaining');

        $this->assertNotFalse($remaining, 'The countdown is missing from the card.');
        $this->assertLessThan($remaining, $due, 'Time remaining belongs under the due date.');
        $this->assertStringNotContainsString('bg-emerald-100', $card, 'Not a green badge.');
    }

    /** Past the deadline there is nothing to count down. */
    public function test_a_closed_assignment_counts_nothing_down(): void
    {
        $this->assignment->update(['due_date' => now()->subDay()]);

        $html = $this->pageFor($this->student);

        $this->assertStringContainsString('Submissions closed', $html);
        $this->assertStringNotContainsString('setInterval(() => tick(), 30000)', $html);
    }

    /** The deadline lives in the description card for the teacher too, once. */
    public function test_the_teacher_card_carries_the_deadline(): void
    {
        $this->assertStringContainsString('Due:', $this->cardFor($this->teacher));
        $this->assertSame(
            1,
            substr_count($this->pageFor($this->teacher), 'Due:'),
            'The due date should appear exactly once.',
        );
    }

    /**
     * The header card is gone entirely: the deadline moved into the
     * description card, the upload limits are set on the material and no
     * longer restated here, and the counts sit beside the title.
     */
    public function test_the_teacher_header_card_is_gone(): void
    {
        $this->assignment->update(['max_file_size_mb' => 50, 'max_files' => 5]);

        $html = $this->pageFor($this->teacher);

        $this->assertStringNotContainsString('Max 50MB per file', $html);
        $this->assertStringNotContainsString('files max per student', $html);
    }

    /** The progress counts share the title's line. */
    public function test_the_counts_sit_beside_the_title(): void
    {
        $html = $this->pageFor($this->teacher);

        $title = strpos($html, '<h1 class="text-2xl');
        $submitted = strpos($html, 'Submitted</p>');
        $card = strpos($html, 'class="prose-page');

        $this->assertNotFalse($submitted, 'The Submitted count is missing.');
        $this->assertLessThan($submitted, $title, 'The title should come first.');
        $this->assertLessThan($card, $submitted, 'The counts belong above the description card.');
    }

    /** An image-only description has no text but is not empty. */
    public function test_an_image_only_description_still_renders(): void
    {
        $this->assignment->update(['body' => '<p><img src="/courses/1/media/materials/x.webp"></p>']);

        $this->assertStringContainsString('class="prose-page', $this->pageFor($this->student));
    }

    /**
     * The styles were lifted out of the page view into a shared partial, so
     * that view has to be checked as well — it is the one that already worked.
     */
    public function test_the_separate_page_view_still_gets_its_styles(): void
    {
        $page = Material::factory()->create([
            'section_id' => $this->assignment->section_id,
            'type' => Material::TYPE_PAGE,
            'is_published' => true,
            'body' => '<h1>Chapter One</h1>',
        ]);

        $html = $this->actingAs($this->student)
            ->get(route('materials.view', $page))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('.prose-page h1 {', $html);
        $this->assertStringContainsString('<h1>Chapter One</h1>', $html);
    }

    /** @once in the partial: including it twice must not duplicate the CSS. */
    public function test_the_stylesheet_is_emitted_only_once(): void
    {
        $this->assertSame(
            1,
            substr_count($this->pageFor($this->student), '.prose-page h1 {'),
            'The prose-page stylesheet was emitted more than once.',
        );
    }
}
