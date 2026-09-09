<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Creating a course is a permission (courses.create), not the admin role.
 * Whoever holds it can open the New course page, save one, and sees the
 * button on the course list; nobody else can, however many other course
 * permissions they hold. Admins still pass through Gate::before.
 */
class CourseCreatePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** A staff account with exactly the given permissions and nothing else. */
    private function staffWith(array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => 'coordinator', 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('coordinator');

        return $user;
    }

    private function create(User $as)
    {
        return $this->actingAs($as)->post(route('courses.store'), [
            'code' => 'NEW-101',
            'name' => 'Newly Created',
            'is_active' => '1',
        ]);
    }

    public function test_the_permission_is_in_the_catalog_and_seeded(): void
    {
        $this->assertDatabaseHas('permissions', ['name' => 'courses.create', 'guard_name' => 'web']);
    }

    public function test_a_holder_can_open_the_page_and_create_a_course(): void
    {
        $holder = $this->staffWith(['courses.create']);

        $this->actingAs($holder)->get(route('courses.create'))->assertOk();
        $this->create($holder)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('courses', ['code' => 'NEW-101']);
    }

    public function test_every_other_course_permission_does_not_add_up_to_create(): void
    {
        $manager = $this->staffWith([
            'courses.view', 'courses.delete', 'courses.activate', 'courses.manage_details',
            'courses.manage_teachers', 'courses.manage_students', 'sections.manage',
        ]);

        $this->actingAs($manager)->get(route('courses.create'))->assertForbidden();
        $this->create($manager)->assertForbidden();

        $this->assertDatabaseMissing('courses', ['code' => 'NEW-101']);
    }

    public function test_an_admin_still_creates_courses(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('courses.create'))->assertOk();
        $this->create($admin)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('courses', ['code' => 'NEW-101']);
    }

    public function test_the_new_course_button_follows_the_permission(): void
    {
        Course::factory()->create(['is_active' => true]);

        $holder = $this->staffWith(['courses.view', 'courses.create']);
        $this->actingAs($holder)
            ->get(route('courses.index'))
            ->assertOk()
            ->assertSee(route('courses.create'), false);

        // A second actingAs in one test does not take without this (see
        // RouteAccessMatrixTest): the first session lingers and answers 401.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $viewer = $this->staffWith(['courses.view']);
        $this->actingAs($viewer)
            ->get(route('courses.index'))
            ->assertOk()
            ->assertDontSee(route('courses.create'), false);
    }
}
