<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * flatpickr is served from our own build, not jsdelivr.
 *
 * It was always a pinned dependency in package.json and installed on every
 * `npm install`, yet the views fetched it from a CDN — with no integrity
 * hash, on pages where users and roles are managed. A hijacked CDN could put
 * arbitrary JavaScript on exactly the screens with the most authority.
 *
 * calendar.js had already solved this for the calendar page; these tests hold
 * the rest of the app to the same rule.
 */
class FlatpickrIsBundledTest extends TestCase
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

    public function test_the_announcement_form_bundles_flatpickr(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('announcements.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('cdn.jsdelivr.net/npm/flatpickr', $html,
            'flatpickr should come from our build, not a third party.');
        $this->assertStringContainsString('/build/assets/flatpickr', $html,
            'The Vite entry should be emitted on this page.');
    }

    public function test_the_course_editor_bundles_flatpickr(): void
    {
        $course = Course::factory()->create(['is_active' => true]);

        $html = $this->actingAs($this->admin())
            ->get(route('courses.edit', $course))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('cdn.jsdelivr.net/npm/flatpickr', $html);
        $this->assertStringContainsString('/build/assets/flatpickr', $html);
    }

    /**
     * No view anywhere may reach for the CDN copy again.
     *
     * A page-by-page assertion only covers the pages someone thought to test;
     * this catches a new view being added with the old snippet pasted in.
     */
    public function test_no_view_references_the_cdn_copy(): void
    {
        $offenders = [];

        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($dir as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $body = file_get_contents($file->getPathname());
                if (str_contains($body, 'jsdelivr.net/npm/flatpickr')) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders,
            'These views still load flatpickr from jsdelivr: '.implode(', ', $offenders));
    }
}
