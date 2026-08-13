<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Render coverage for the user create/edit screens and the /users index
 * filter bar.
 *
 * These pages had no tests, which mattered when the role and course dropdown
 * options moved out of the Blade templates and into the controller: a missed
 * view variable would have 500'd both forms with nothing to catch it.
 */
class UserFormPagesTest extends TestCase
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

    public function test_create_page_renders_with_assignable_role_options(): void
    {
        $this->actingAs($this->admin)->get('/users/create')
            ->assertOk()
            ->assertSee('Student')
            ->assertSee('Teacher');
    }

    public function test_edit_page_renders_with_the_users_role_preselected(): void
    {
        $student = User::factory()->create(['name' => 'Editable Person']);
        $student->assignRole('student');

        $this->actingAs($this->admin)->get("/users/{$student->id}/edit")
            ->assertOk()
            ->assertSee('Editable Person')
            ->assertSee('Student');
    }

    /**
     * `admin` is deliberately not offered in the role picker — it's granted
     * by seeding, never through this form.
     */
    public function test_admin_is_not_offered_as_an_assignable_role(): void
    {
        Role::firstOrCreate(['name' => 'tutor', 'guard_name' => 'web']);

        $response = $this->actingAs($this->admin)->get('/users/create')->assertOk();

        $response->assertSee('Tutor');
        // The role pills render ucfirst()'d names; "Admin" must not be one.
        $this->assertStringNotContainsString('value="admin"', $response->getContent());
    }

    public function test_index_filter_dropdowns_render_roles_and_courses(): void
    {
        Course::factory()->create(['code' => 'BIO-201', 'name' => 'Biology']);

        $this->actingAs($this->admin)->get('/users')
            ->assertOk()
            ->assertSee('BIO-201')
            ->assertSee('All Roles')
            ->assertSee('All Courses');
    }

    public function test_non_admin_cannot_reach_the_user_forms(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($student)->get('/users/create')->assertForbidden();
        $this->actingAs($student)->get("/users/{$this->admin->id}/edit")->assertForbidden();
    }
}
