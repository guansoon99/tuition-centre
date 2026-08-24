<?php

namespace Tests\Feature\Teacher;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Support\PrivateFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PDF and Link materials can carry a body too.
 *
 * The column was always shared; only Media, Page and Assignment ever wrote to
 * it. For PDF and Link it is an optional note shown under the row — empty
 * stays empty, and it is never required the way a Media body is.
 */
class MaterialBodyOnPdfAndLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(PrivateFile::disk());

        $course = Course::factory()->create(['is_active' => true]);
        $this->section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);

        $staffRole = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $staffRole->givePermissionTo('sections.manage');

        $this->teacher = User::factory()->create(['is_active' => true]);
        $this->teacher->assignRole($staffRole);

        Enrollment::create([
            'course_id' => $course->id, 'user_id' => $this->teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    private function store(array $payload)
    {
        return $this->actingAs($this->teacher)
            ->post(route('materials.store', $this->section), $payload);
    }

    // ---- Saving -------------------------------------------------------------

    public function test_a_link_keeps_its_body(): void
    {
        $this->store([
            'title' => 'Reading',
            'type' => Material::TYPE_EXTERNAL_LINK,
            'external_url' => 'https://example.test/notes',
            'body' => '<p>Read section 3 first.</p>',
        ])->assertSessionHasNoErrors();

        $created = Material::where('title', 'Reading')->firstOrFail();

        $this->assertStringContainsString('Read section 3 first.', $created->body);
        $this->assertSame('https://example.test/notes', $created->external_url);
    }

    public function test_a_pdf_keeps_its_body(): void
    {
        $this->store([
            'title' => 'Worksheet',
            'type' => Material::TYPE_PDF,
            'file' => UploadedFile::fake()->create('w.pdf', 10, 'application/pdf'),
            'body' => '<p>Print double sided.</p>',
        ])->assertSessionHasNoErrors();

        $created = Material::where('title', 'Worksheet')->firstOrFail();

        $this->assertStringContainsString('Print double sided.', $created->body);
        $this->assertNotNull($created->file_path, 'The upload must still be stored.');
    }

    /** Optional means optional — unlike Media, which requires one. */
    public function test_a_link_without_a_body_is_still_accepted(): void
    {
        $this->store([
            'title' => 'Bare Link',
            'type' => Material::TYPE_EXTERNAL_LINK,
            'external_url' => 'https://example.test',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(Material::where('title', 'Bare Link')->first());
    }

    /** The body is sanitised on this path too, not just for Media. */
    public function test_a_body_on_a_link_is_sanitised(): void
    {
        $this->store([
            'title' => 'Nasty',
            'type' => Material::TYPE_EXTERNAL_LINK,
            'external_url' => 'https://example.test',
            'body' => '<p>Fine</p><script>alert(1)</script>',
        ])->assertSessionHasNoErrors();

        $body = Material::where('title', 'Nasty')->value('body');

        $this->assertStringContainsString('Fine', $body);
        $this->assertStringNotContainsString('<script', $body);
    }

    // ---- Editing ------------------------------------------------------------

    public function test_editing_a_pdf_without_touching_the_file_keeps_both(): void
    {
        $this->store([
            'title' => 'Sheet',
            'type' => Material::TYPE_PDF,
            'file' => UploadedFile::fake()->create('s.pdf', 10, 'application/pdf'),
            'body' => '<p>First note.</p>',
        ])->assertSessionHasNoErrors();

        $material = Material::where('title', 'Sheet')->firstOrFail();
        $originalPath = $material->file_path;

        $this->actingAs($this->teacher)
            ->patch(route('materials.update', $material), [
                'title' => 'Sheet',
                'type' => Material::TYPE_PDF,
                'body' => '<p>Second note.</p>',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $material->fresh();

        $this->assertStringContainsString('Second note.', $fresh->body);
        $this->assertSame($originalPath, $fresh->file_path, 'The PDF must survive a body-only edit.');
    }

    // ---- Rendering ----------------------------------------------------------

    /**
     * The note renders outside the row's anchor.
     *
     * A body may contain a link, and an <a> nested in an <a> is invalid — the
     * browser closes the outer one early and the row stops navigating. This
     * asserts the note's markup is not swallowed by the anchor.
     */
    public function test_the_note_is_rendered_outside_the_row_link(): void
    {
        $this->store([
            'title' => 'Linky',
            'type' => Material::TYPE_EXTERNAL_LINK,
            'external_url' => 'https://example.test',
            'body' => '<p>See <a href="https://other.test" target="_blank" rel="noopener">this</a>.</p>',
        ])->assertSessionHasNoErrors();

        $material = Material::where('title', 'Linky')->firstOrFail();
        $material->update(['is_published' => true]);

        $html = $this->actingAs($this->teacher)
            ->get(route('courses.edit', [$this->section->course, 'tab' => 'materials']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('prose-section', $html);
        $this->assertStringContainsString('other.test', $html);

        // The row anchor must be closed before the note begins.
        $rowStart = strpos($html, 'https://example.test');
        $noteStart = strpos($html, 'other.test');
        $this->assertNotFalse($rowStart);
        $this->assertNotFalse($noteStart);
        $this->assertStringContainsString(
            '</a>',
            substr($html, $rowStart, $noteStart - $rowStart),
            'The note must come after the row link is closed.',
        );
    }
}
