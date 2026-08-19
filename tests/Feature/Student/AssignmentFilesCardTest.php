<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\Submission;
use App\Models\User;
use App\Support\CourseMedia;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The files and the upload form, as the "File submission" row of the
 * Submission Status card.
 *
 * The list is what a student checks before adding to it, so they sit together
 * in one row rather than in a card of their own. The upload area keeps no
 * heading — the row's label covers both halves.
 */
class AssignmentFilesCardTest extends TestCase
{
    use RefreshDatabase;

    private Material $assignment;

    private User $student;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $this->course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        $this->assignment = Material::factory()->create([
            'section_id' => $section->id,
            'type' => Material::TYPE_ASSIGNMENT,
            'is_published' => true,
            'due_date' => now()->addWeek(),
            'max_files' => 2,
        ]);

        $this->student = User::factory()->create(['is_active' => true]);
        $this->student->assignRole('student');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->student->id,
            'role_on_course' => Enrollment::ROLE_STUDENT, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    private function addFiles(int $count): void
    {
        $submission = Submission::firstOrCreate(
            ['material_id' => $this->assignment->id, 'user_id' => $this->student->id],
            ['submitted_at' => now()],
        );

        for ($i = 0; $i < $count; $i++) {
            $submission->files()->create([
                'file_path' => CourseMedia::assignmentFolder(
                    $this->course->id, $this->assignment->id, $this->student->id
                )."/f{$i}.pdf",
                'original_name' => "f{$i}.pdf",
                'size_bytes' => 10,
                'mime_type' => 'application/pdf',
                'uploaded_at' => now(),
            ]);
        }
    }

    private function page(): string
    {
        return $this->actingAs($this->student)
            ->get(route('materials.view', $this->assignment))
            ->assertOk()
            ->getContent();
    }

    public function test_the_upload_form_sits_under_the_file_submission_label(): void
    {
        $this->addFiles(1);
        $html = $this->page();

        $label = strpos($html, 'File submission');
        $rule = strpos($html, '<hr class="my-4');
        $form = strpos($html, route('submissions.upload', $this->assignment));

        $this->assertNotFalse($label, 'The File submission label is missing.');
        $this->assertNotFalse($form, 'The upload form is missing.');
        $this->assertLessThan($rule, $label, 'The file list should come before the divider.');
        $this->assertLessThan($form, $rule, 'The divider should come before the upload form.');
    }

    /** The card heading covers both halves, so the second one is redundant. */
    public function test_the_separate_upload_heading_is_gone(): void
    {
        $this->assertStringNotContainsString('>Upload Files<', $this->page());
    }

    /** One row, so one label and one upload form. */
    public function test_there_is_exactly_one_of_each(): void
    {
        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'File submission'));
        $this->assertSame(1, substr_count($html, 'name="files[]"'));
    }

    /** Past the deadline there is nothing to divide, so no rule and no form. */
    public function test_a_closed_assignment_shows_the_list_alone(): void
    {
        $this->addFiles(1);
        $this->assignment->update(['due_date' => now()->subDay()]);

        $html = $this->page();

        $this->assertStringContainsString('File submission', $html);
        $this->assertStringNotContainsString('<hr class="my-4', $html);
        $this->assertStringNotContainsString('name="files[]"', $html);
    }

    /**
     * The cap message used to point "below" at the file list. The list is now
     * above the upload area, so the wording had to follow it.
     */
    public function test_the_cap_message_points_at_the_list_above_it(): void
    {
        $this->addFiles(2);
        $html = $this->page();

        $this->assertStringContainsString('Remove a file above to upload another.', $html);
        $this->assertStringNotContainsString('Remove a file below', $html);
    }

    /**
     * With no files yet the row is just the picker.
     *
     * The old "No files uploaded yet." line said what the empty file picker
     * directly beneath it already says.
     */
    public function test_an_empty_row_is_just_the_upload_form(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('No files uploaded yet', $html);
        $this->assertStringContainsString('name="files[]"', $html);
        $this->assertStringNotContainsString('<hr class="my-4', $html,
            'A divider with no file list above it divides nothing.');
    }

    /** The divider only appears once there is a list to separate. */
    public function test_the_divider_appears_only_with_files_above_it(): void
    {
        $this->addFiles(1);

        $this->assertStringContainsString('<hr class="my-4', $this->page());
    }

    /**
     * Past the deadline there is no picker, so an empty row would say nothing
     * at all — that is the one case the message still earns its place.
     */
    public function test_a_closed_empty_row_explains_itself(): void
    {
        $this->assignment->update(['due_date' => now()->subDay()]);

        $html = $this->page();

        // Neutral wording, because the teacher's modal renders the same table
        // and "You did not submit" would be addressed to the wrong person.
        $this->assertStringContainsString('Nothing was submitted before the deadline.', $html);
        $this->assertStringNotContainsString('name="files[]"', $html);
    }

    /** One file per upload — picking is the action, so a multi-select is wrong. */
    public function test_the_picker_takes_one_file_at_a_time(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('name="files[]"', $html);
        $this->assertStringNotContainsString('name="files[]" multiple', $html);
    }

    /** Choosing a file starts the upload, so there is nothing left to click. */
    public function test_choosing_a_file_starts_the_upload(): void
    {
        $html = $this->page();

        $this->assertMatchesRegularExpression(
            '/if \(this\.chosen\.length > 0 && this\.problems\.length === 0\) \{\s*this\.start\(\);/',
            $html,
            'pick() should start the upload once its checks pass.',
        );
    }

    /**
     * The only Upload button left is the no-JS fallback. Without Alpine the
     * change handler never runs, so that form needs a way to be submitted.
     */
    public function test_the_upload_button_exists_only_for_no_javascript(): void
    {
        // Scoped to the upload form: the layout has its own submit buttons
        // (logout, for one) and a page-wide count would measure those.
        preg_match(
            '/<form method="POST" action="'.preg_quote(route('submissions.upload', $this->assignment), '/').'".*?<\/form>/s',
            $this->page(),
            $m,
        );
        $form = $m[0] ?? '';

        $this->assertNotSame('', $form, 'Could not find the upload form.');
        $this->assertSame(1, substr_count($form, 'type="submit"'), 'Expected exactly one submit button.');
        $this->assertMatchesRegularExpression('/<noscript>\s*<button type="submit"/', $form);
    }

    /**
     * $root, not $el.
     *
     * Alpine binds $el to the element an expression ran on. The proxied-upload
     * fallback is now reachable from pick(), which is bound to the <input> —
     * and an input has no submit(), so $el threw a TypeError and the fallback
     * silently died. That is the path a student on a network blocking R2
     * depends on, so it fails quietly for exactly the people who need it.
     */
    public function test_the_form_fallback_targets_the_form_not_the_input(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('this.$el.submit()', $html);
        $this->assertSame(2, substr_count($html, 'this.$root.submit()'),
            'Both fallbacks — no-presign and R2-unreachable — must submit the form.');
    }
}
