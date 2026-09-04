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
 * The colour a countdown card is painted.
 *
 * The database stores a key, never CSS. Tailwind builds its stylesheet by
 * scanning source files for literal class names, so a gradient assembled from
 * a stored value would compile to no CSS at all — and silently, since nothing
 * errors when a class simply does not exist. The key maps to a fixed string in
 * Material::COUNTDOWN_THEMES, and these tests hold that arrangement in place.
 */
class CountdownThemeTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Section $section;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web'])
            ->givePermissionTo(['sections.manage', 'courses.view']);

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

    private function countdown(?string $theme): Material
    {
        return Material::factory()->create([
            'section_id' => $this->section->id,
            'type' => Material::TYPE_COUNTDOWN,
            'is_published' => true,
            'target_date' => now()->addWeek(),
            'countdown_theme' => $theme,
        ]);
    }

    // ---- The themes themselves ----------------------------------------------

    public function test_there_are_six_themes(): void
    {
        $this->assertCount(6, Material::COUNTDOWN_THEMES);
    }

    /**
     * Every theme must be a whole, literal class string.
     *
     * A gradient built by interpolation — "from-{$colour}-500" — is invisible
     * to Tailwind's scanner and produces no CSS, so the card would render with
     * no background and nothing would report a problem.
     */
    public function test_every_theme_names_all_three_gradient_stops(): void
    {
        foreach (Material::COUNTDOWN_THEMES as $key => $theme) {
            $this->assertArrayHasKey('label', $theme, $key);
            $this->assertNotSame('', $theme['label'], $key);

            foreach (['from-', 'via-', 'to-'] as $stop) {
                $this->assertStringContainsString($stop, $theme['classes'],
                    "Theme {$key} is missing a {$stop} stop.");
            }

            $this->assertStringNotContainsString('{', $theme['classes'],
                "Theme {$key} looks interpolated; Tailwind cannot see those.");
        }
    }

    public function test_the_default_is_a_real_theme(): void
    {
        $this->assertArrayHasKey(Material::COUNTDOWN_THEME_DEFAULT, Material::COUNTDOWN_THEMES);
    }

    // ---- Resolving ----------------------------------------------------------

    public function test_a_material_resolves_its_own_theme(): void
    {
        $material = $this->countdown('mint');

        $this->assertSame(
            Material::COUNTDOWN_THEMES['mint']['classes'],
            $material->countdownThemeClasses(),
        );
    }

    /** Existing rows predate the column, so null has to mean something. */
    public function test_no_theme_falls_back_to_the_default(): void
    {
        $material = $this->countdown(null);

        $this->assertSame(
            Material::COUNTDOWN_THEMES[Material::COUNTDOWN_THEME_DEFAULT]['classes'],
            $material->countdownThemeClasses(),
        );
    }

    /** A key removed from the map later must not blank the card. */
    public function test_an_unknown_theme_falls_back_to_the_default(): void
    {
        $material = $this->countdown('a-theme-that-was-deleted');

        $this->assertSame(
            Material::COUNTDOWN_THEMES[Material::COUNTDOWN_THEME_DEFAULT]['classes'],
            $material->countdownThemeClasses(),
        );
    }

    // ---- Rendering ----------------------------------------------------------

    public function test_the_card_renders_the_chosen_gradient(): void
    {
        $material = $this->countdown('sunset');

        $html = $this->actingAs($this->teacher)
            ->get("/courses/{$this->course->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(Material::COUNTDOWN_THEMES['sunset']['classes'], $html);
        $this->assertStringNotContainsString(Material::COUNTDOWN_THEMES['mint']['classes'], $html);
    }

    // ---- Saving -------------------------------------------------------------

    /**
     * Both modals offer the full set.
     *
     * They are lazily-fetched fragments rather than markup on the course page,
     * so each has to be asked for by its own route — and they are separate
     * templates, so adding a theme to one and not the other is an easy miss.
     */
    public function test_both_modals_offer_every_theme(): void
    {
        $material = $this->countdown('ocean');

        $pages = [
            'edit modal' => route('materials.edit-modal', $material),
            'create modal' => route('materials.create-modal', $this->section),
        ];

        foreach ($pages as $where => $url) {
            $html = $this->actingAs($this->teacher)->get($url)->assertOk()->getContent();

            foreach (Material::COUNTDOWN_THEMES as $key => $theme) {
                $this->assertStringContainsString('value="'.$key.'"', $html, "{$where}: {$key}");
                $this->assertStringContainsString($theme['classes'], $html, "{$where}: {$key}");
            }
        }
    }

    /** The edit modal opens on the colour the material already has. */
    public function test_the_edit_modal_preselects_the_current_theme(): void
    {
        $material = $this->countdown('sunset');

        $html = $this->actingAs($this->teacher)
            ->get(route('materials.edit-modal', $material))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/value="sunset"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/value="mint"[^>]*checked/', $html);
    }

    public function test_choosing_a_theme_saves_it(): void
    {
        $material = $this->countdown(null);

        $this->actingAs($this->teacher)
            ->patch(route('materials.update', $material), [
                'title' => $material->title,
                'type' => Material::TYPE_COUNTDOWN,
                'target_date' => now()->addWeek()->format('Y-m-d H:i'),
                'countdown_theme' => 'rose',
            ])
            ->assertRedirect();

        $this->assertSame('rose', $material->fresh()->countdown_theme);
    }

    /** A key that is not in the map is rejected rather than stored. */
    public function test_an_invalid_theme_is_refused(): void
    {
        $material = $this->countdown('mint');

        $this->actingAs($this->teacher)
            ->patch(route('materials.update', $material), [
                'title' => $material->title,
                'type' => Material::TYPE_COUNTDOWN,
                'target_date' => now()->addWeek()->format('Y-m-d H:i'),
                'countdown_theme' => 'bg-red-500; drop table materials',
            ])
            ->assertSessionHasErrors('countdown_theme');

        $this->assertSame('mint', $material->fresh()->countdown_theme);
    }

    /**
     * Changing a countdown into something else must not leave the colour
     * behind — the controller blanks every type-specific column on save, and
     * this one has to be in that list.
     */
    public function test_switching_away_from_countdown_clears_the_theme(): void
    {
        $material = $this->countdown('violet');

        $this->actingAs($this->teacher)
            ->patch(route('materials.update', $material), [
                'title' => $material->title,
                'type' => Material::TYPE_ANNOUNCEMENT,
                'body' => '<p>Notis</p>',
            ])
            ->assertRedirect();

        $this->assertNull($material->fresh()->countdown_theme);
    }
}
