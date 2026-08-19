<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\Material;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Long material content must not push the edit button off its row.
 *
 * Two separate things caused this, and removing either one brings it back:
 *
 *  1. The row is a flex container. A flex item defaults to min-width:auto, so
 *     without min-w-0 the content column refuses to shrink below its content's
 *     intrinsic minimum and the row grows instead — measured in a browser at a
 *     700px viewport, a pasted URL pushed the edit button 145px past the right
 *     edge of the screen.
 *  2. min-w-0 alone only lets the box shrink; the text still has to be able to
 *     break. overflow-wrap makes a long unbreakable run wrap, which dropped the
 *     content's minimum width from 664px to 12px.
 *
 * Layout cannot be measured from PHPUnit, so these assert the two ingredients
 * are present. The measurements above are what actually verified the fix.
 */
class MaterialRowLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function materialsTabHtml(string $body): string
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $course = Course::factory()->create(['is_active' => true]);
        $section = Section::factory()->create([
            'course_id' => $course->id, 'is_published' => true, 'scheduled_at' => null,
        ]);
        Material::factory()->create([
            'section_id' => $section->id, 'type' => Material::TYPE_TEXT, 'body' => $body,
        ]);

        return $this->actingAs($admin)
            ->get(route('courses.edit', [$course, 'tab' => 'materials']))
            ->assertOk()
            ->getContent();
    }

    public function test_the_content_column_can_shrink(): void
    {
        $html = $this->materialsTabHtml('<p>Some text</p>');

        $this->assertMatchesRegularExpression(
            '/<div class="[^"]*\bmin-w-0\b[^"]*\bflex-1\b[^"]*">\s*<div class="flex gap-3/',
            $html,
            'The material row content column lost min-w-0 — a long line will push the edit button off the row.',
        );
    }

    public function test_long_unbreakable_text_is_allowed_to_wrap(): void
    {
        $html = $this->materialsTabHtml(
            '<p>https://example.com/a/very/long/unbroken/url/with/no/spaces/anywhere/in/it</p>'
        );

        $this->assertStringContainsString('overflow-wrap: break-word', $html,
            'Without overflow-wrap a pasted URL sets the row minimum width to its own length.');
    }

    /** A wide table is the other way to force a row wider than its container. */
    public function test_wide_tables_scroll_rather_than_stretch_the_row(): void
    {
        $html = $this->materialsTabHtml('<table><tr><td>a</td><td>b</td></tr></table>');

        $this->assertStringContainsString('.prose-section table { display: block; overflow-x: auto', $html);
    }

    /** The edit button is still on the row it belongs to. */
    public function test_the_row_still_has_its_edit_button(): void
    {
        $html = $this->materialsTabHtml('<p>Some text</p>');

        $this->assertStringContainsString('title="Edit material"', $html);
        $this->assertStringContainsString('title="Drag to reorder"', $html);
    }
}
