<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Front-end libraries are served from our own build, not a CDN.
 *
 * flatpickr, Quill, Tom Select and Sortable all used to load from jsdelivr
 * with no integrity hash, on the pages where users, roles and course content
 * are managed. A hijacked CDN would have had script execution on the
 * highest-authority screens in the app. Alpine and FullCalendar had already
 * been moved for the same reason; these tests hold the rest to it.
 */
class BundledAssetsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        return $admin;
    }

    /**
     * The catch-all: no Blade file anywhere may reach for a CDN copy.
     *
     * Page-by-page assertions only cover the pages someone thought to test.
     * This one fails when a new view is added with an old snippet pasted in.
     */
    public function test_no_view_loads_a_library_from_a_cdn(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $body = file_get_contents($file->getPathname());
            if (preg_match('#(jsdelivr|unpkg|cdnjs)\.#i', $body)) {
                $offenders[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders,
            'These views still load from a CDN: '.implode(', ', $offenders));
    }

    public function test_the_course_editor_bundles_quill_and_tom_select(): void
    {
        $course = Course::factory()->create(['is_active' => true]);

        $html = $this->actingAs($this->admin())
            ->get(route('courses.edit', $course))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/build/assets/quill', $html);
        $this->assertStringContainsString('/build/assets/tom-select', $html);
        $this->assertStringContainsString('/build/assets/sortable', $html);
        $this->assertStringContainsString('/build/assets/flatpickr', $html);
    }

    /**
     * Quill's theme must load BEFORE the app's .ql-* overrides.
     *
     * They sit at equal specificity, so the later rule wins — that is the
     * whole reason the @vite call is placed above the <style> blocks rather
     * than tidied to the bottom of the head. Get this backwards and the
     * editor silently reverts to Quill's own sizing and toolbar styling.
     */
    public function test_the_quill_theme_loads_before_the_app_overrides(): void
    {
        $course = Course::factory()->create(['is_active' => true]);

        $html = $this->actingAs($this->admin())
            ->get(route('courses.edit', $course))
            ->assertOk()
            ->getContent();

        $theme = strpos($html, '/build/assets/quill');
        $override = strpos($html, '.ql-editor { min-height: 280px; }');

        $this->assertNotFalse($theme, 'Quill CSS should be on the page.');
        $this->assertNotFalse($override, 'The height override should be on the page.');
        $this->assertLessThan($override, $theme,
            'Quill theme must come first or the app overrides lose the cascade.');
    }

    /** Same rule for Tom Select and the .ts-* overrides. */
    public function test_the_tom_select_theme_loads_before_the_app_overrides(): void
    {
        $course = Course::factory()->create(['is_active' => true]);

        $html = $this->actingAs($this->admin())
            ->get(route('courses.edit', $course))
            ->assertOk()
            ->getContent();

        $theme = strpos($html, '/build/assets/tom-select');
        $override = strpos($html, '.ts-wrapper.single .ts-control');

        $this->assertNotFalse($theme);
        $this->assertNotFalse($override);
        $this->assertLessThan($override, $theme);
    }

    public function test_the_reorderable_admin_lists_bundle_sortable(): void
    {
        $admin = $this->admin();

        foreach (['announcements.index', 'banner.index', 'contacts.index'] as $route) {
            // A second actingAs in one test does not take without this.
            $this->app['auth']->forgetGuards();
            $this->flushSession();

            $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();
            $this->assertStringContainsString('/build/assets/sortable', $html,
                "{$route} should bundle Sortable.");
        }
    }

    /** Every entry publishes its global, since all callers are inline. */
    public function test_each_entry_exposes_the_global_its_callers_use(): void
    {
        $expected = [
            'quill.js' => 'window.Quill',
            'tom-select.js' => 'window.TomSelect',
            'sortable.js' => 'window.Sortable',
            'flatpickr.js' => 'window.flatpickr',
        ];

        foreach ($expected as $file => $global) {
            $this->assertStringContainsString(
                $global,
                file_get_contents(resource_path('js/'.$file)),
                "{$file} must set {$global} — its callers are inline and cannot import."
            );
        }
    }
}
