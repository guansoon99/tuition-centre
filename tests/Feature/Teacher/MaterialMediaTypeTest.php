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
 * The material type formerly known as 'text'.
 *
 * The picker has said "Media" for a while; the stored value said 'text', so
 * the label and the data disagreed. The value is now 'media' and existing rows
 * were rewritten by the 2026_08_24 migration.
 *
 * What makes this worth testing is that the type string is not only a constant
 * — it also appears inside `required_if:type,...` rules, where a literal will
 * happily go stale without failing anything. That is exactly what happened
 * here: the body rule still said `required_if:type,text` after the rename, so
 * a Media material could be saved with no body at all.
 */
class MaterialMediaTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $course = Course::factory()->create(['is_active' => true]);
        $this->section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);

        // The seeder creates the permissions but not this role.
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
            ->post(route('materials.store', $this->section), array_merge([
                'title' => 'A Lesson',
            ], $payload));
    }

    // ---- The stored value ---------------------------------------------------

    public function test_the_constant_is_media(): void
    {
        $this->assertSame('media', Material::TYPE_MEDIA);
    }

    public function test_creating_one_stores_media_not_text(): void
    {
        $this->store(['type' => 'media', 'body' => '<p>Hello</p>'])
            ->assertSessionHasNoErrors();

        $created = Material::where('title', 'A Lesson')->firstOrFail();

        $this->assertSame('media', $created->type);
        $this->assertNotSame('text', $created->type);
    }

    /** The old value is no longer a type, so it must be refused outright. */
    public function test_the_old_text_value_is_rejected(): void
    {
        $this->store(['type' => 'text', 'body' => '<p>Hello</p>'])
            ->assertSessionHasErrors('type');

        $this->assertNull(Material::where('title', 'A Lesson')->first());
    }

    // ---- The rule that silently went stale ----------------------------------

    /**
     * A Media material still requires a body.
     *
     * This is the assertion that catches `required_if:type,text` being left
     * behind: with the stale literal the rule matches nothing, an empty body
     * sails through, and the only symptom is a blank material on the page.
     */
    public function test_a_media_material_still_requires_a_body(): void
    {
        $this->store(['type' => 'media', 'body' => ''])
            ->assertSessionHasErrors('body');

        $this->assertNull(Material::where('title', 'A Lesson')->first());
    }

    /** Same rule, same trap, for the "open on a separate page" variant. */
    public function test_a_page_material_still_requires_a_body(): void
    {
        $this->store(['type' => 'page', 'body' => ''])
            ->assertSessionHasErrors('body');
    }

    /** And the requirement must not leak onto types that have no body. */
    public function test_a_countdown_does_not_require_a_body(): void
    {
        $this->store(['type' => 'countdown', 'target_date' => now()->addWeek()->format('Y-m-d H:i')])
            ->assertSessionHasNoErrors();

        $this->assertSame('countdown', Material::where('title', 'A Lesson')->value('type'));
    }

    // ---- The picker ---------------------------------------------------------

    public function test_the_form_offers_media_as_the_value_and_defaults_to_pdf(): void
    {
        $html = $this->actingAs($this->teacher)
            ->get(route('materials.create', $this->section))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="media"', $html);
        $this->assertStringNotContainsString('value="text"', $html);

        // A new material starts on PDF, the first pill in the row.
        $this->assertStringContainsString("type: 'pdf'", $html);
    }
}
