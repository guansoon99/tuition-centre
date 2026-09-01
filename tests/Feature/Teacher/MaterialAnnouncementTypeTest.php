<?php

namespace Tests\Feature\Teacher;

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
 * The Announcement material type.
 *
 * Media in every respect but the icon and the label: same rich-text body,
 * same inline rendering, same validation. It exists so a teacher can mark a
 * block as an announcement and have it read that way in the list.
 *
 * Not to be confused with the Announcement model — the site-wide notice
 * system with its own table, audience and schedule. They share a word and
 * nothing else.
 */
class MaterialAnnouncementTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Section $section;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web'])
            ->givePermissionTo('sections.manage');

        $this->course = Course::factory()->create(['is_active' => true]);
        $this->section = Section::factory()->create([
            'course_id' => $this->course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);

        $this->teacher = User::factory()->create(['is_active' => true]);
        $this->teacher->assignRole('teacher');
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $this->teacher->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    private function store(array $payload)
    {
        return $this->actingAs($this->teacher)
            ->post(route('materials.store', $this->section), array_merge([
                'title' => 'Notice',
            ], $payload));
    }

    private function coursePage(): string
    {
        return $this->actingAs($this->teacher)
            ->get("/courses/{$this->course->slug}")
            ->assertOk()
            ->getContent();
    }

    // ---- Saving -------------------------------------------------------------

    public function test_the_constant_is_announcement(): void
    {
        $this->assertSame('announcement', Material::TYPE_ANNOUNCEMENT);
    }

    public function test_an_announcement_can_be_created(): void
    {
        $this->store([
            'type' => Material::TYPE_ANNOUNCEMENT,
            'body' => '<p>Class is cancelled on Friday.</p>',
        ])->assertSessionHasNoErrors();

        $created = Material::where('title', 'Notice')->firstOrFail();

        $this->assertSame('announcement', $created->type);
        $this->assertStringContainsString('Class is cancelled on Friday.', $created->body);
    }

    /** Same rule as Media: the body is the material, so it is required. */
    public function test_an_announcement_requires_a_body(): void
    {
        $this->store(['type' => Material::TYPE_ANNOUNCEMENT, 'body' => ''])
            ->assertSessionHasErrors('body');

        $this->assertNull(Material::where('title', 'Notice')->first());
    }

    /** The body goes through the sanitizer, exactly as Media's does. */
    public function test_the_body_is_sanitised(): void
    {
        $this->store([
            'type' => Material::TYPE_ANNOUNCEMENT,
            'body' => '<p>Fine</p><script>alert(1)</script>',
        ])->assertSessionHasNoErrors();

        $body = Material::where('title', 'Notice')->value('body');

        $this->assertStringContainsString('Fine', $body);
        $this->assertStringNotContainsString('<script', $body);
    }

    public function test_an_existing_material_can_be_switched_to_announcement(): void
    {
        $material = Material::factory()->create([
            'section_id' => $this->section->id,
            'type' => Material::TYPE_MEDIA,
            'title' => 'Was Media',
            'body' => '<p>Body</p>',
        ]);

        $this->actingAs($this->teacher)
            ->patch(route('materials.update', $material), [
                'title' => 'Was Media',
                'type' => Material::TYPE_ANNOUNCEMENT,
                'body' => '<p>Body</p>',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('announcement', $material->fresh()->type);
    }

    // ---- Rendering ----------------------------------------------------------

    public function test_it_renders_inline_with_the_announcement_icon(): void
    {
        Material::factory()->create([
            'section_id' => $this->section->id,
            'type' => Material::TYPE_ANNOUNCEMENT,
            'title' => 'Sports Day Notice',
            'body' => '<p>Bring your kit.</p>',
            'is_published' => true,
        ]);

        $html = $this->coursePage();

        $this->assertStringContainsString('Bring your kit.', $html, 'The body renders inline.');
        $this->assertStringContainsString('images/icons/announcement.webp', $html);
        $this->assertStringContainsString('alt="Announcement"', $html);
    }

    /** And Media keeps its own icon — the two must not collapse into one. */
    public function test_a_media_material_still_uses_the_media_icon(): void
    {
        Material::factory()->create([
            'section_id' => $this->section->id,
            'type' => Material::TYPE_MEDIA,
            'title' => 'Lesson One',
            'body' => '<p>Read chapter 3.</p>',
            'is_published' => true,
        ]);

        $html = $this->coursePage();

        $this->assertStringContainsString('images/icons/media.webp', $html);
        $this->assertStringNotContainsString('images/icons/announcement.webp', $html);
    }

    public function test_both_types_can_sit_in_one_section(): void
    {
        Material::factory()->create([
            'section_id' => $this->section->id, 'type' => Material::TYPE_MEDIA,
            'title' => 'A Lesson', 'body' => '<p>Lesson body.</p>', 'is_published' => true,
        ]);
        Material::factory()->create([
            'section_id' => $this->section->id, 'type' => Material::TYPE_ANNOUNCEMENT,
            'title' => 'A Notice', 'body' => '<p>Notice body.</p>', 'is_published' => true,
        ]);

        $html = $this->coursePage();

        $this->assertStringContainsString('Lesson body.', $html);
        $this->assertStringContainsString('Notice body.', $html);
        $this->assertStringContainsString('images/icons/media.webp', $html);
        $this->assertStringContainsString('images/icons/announcement.webp', $html);
    }

    // ---- The picker ---------------------------------------------------------

    public function test_the_form_offers_announcement_between_media_and_assignment(): void
    {
        $html = $this->actingAs($this->teacher)
            ->get(route('materials.create', $this->section))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="announcement"', $html);
        $this->assertStringContainsString('Announcement', $html);

        // Order matters: the row reads PDF, Link, Media, Announcement, ...
        $media = strpos($html, 'value="media"');
        $announcement = strpos($html, 'value="announcement"');
        $assignment = strpos($html, 'value="assignment"');

        $this->assertLessThan($announcement, $media);
        $this->assertLessThan($assignment, $announcement);
    }
}
