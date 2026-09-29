<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The list pages must not be wider than a phone screen, or the whole page
 * can be dragged sideways. Two things did that on /users: the row of page
 * numbers (it could not wrap) and the course filter (a dropdown is as wide
 * as its longest option). The pager now wraps and the filters are capped at
 * the width of their box.
 */
class PhoneWidthListsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    /** The class list of every filter dropdown on the page. */
    private function filterSelects(string $html): array
    {
        preg_match_all('/<select name="[^"]+" onchange="this\.form\.submit\(\)"\s+class="([^"]*)"/', $html, $m);

        return $m[1];
    }

    public function test_the_page_numbers_wrap_instead_of_widening_the_page(): void
    {
        User::factory()->count(60)->create();

        $html = $this->actingAs($this->admin)->get(route('users.index'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div class="([^"]*)" data-pager-buttons>/', $html, $m), 'The pager is missing.');
        $classes = explode(' ', $m[1]);
        $this->assertContains('flex-wrap', $classes);
        $this->assertContains('max-w-full', $classes);
        $this->assertNotContains('inline-flex', $classes);
    }

    public function test_the_users_filters_cannot_be_wider_than_their_box(): void
    {
        Course::factory()->create(['name' => str_repeat('A very long course name ', 6)]);

        $html = $this->actingAs($this->admin)->get(route('users.index'))->assertOk()->getContent();

        $selects = $this->filterSelects($html);
        $this->assertCount(4, $selects);
        foreach ($selects as $classes) {
            $this->assertContains('max-w-full', explode(' ', $classes));
        }
    }

    public function test_the_courses_filters_cannot_be_wider_than_their_box(): void
    {
        $html = $this->actingAs($this->admin)->get(route('courses.index'))->assertOk()->getContent();

        $selects = $this->filterSelects($html);
        $this->assertCount(1, $selects);
        foreach ($selects as $classes) {
            $this->assertContains('max-w-full', explode(' ', $classes));
        }
    }
}
