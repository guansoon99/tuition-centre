<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Self-assignment on the Teachers tab. A non-admin holding Manage Teachers
 * may add themselves to a course (the permission is the trust) but may not
 * remove themselves; that needs an admin. Admins may do both.
 */
class TeacherSelfAssignTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    /** Staff account with Manage Teachers, not in the admin role. */
    private User $coordinator;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $staff = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $staff->givePermissionTo(['courses.manage_teachers', 'courses.view']);

        $this->course = Course::factory()->create(['is_active' => true]);

        $this->coordinator = User::factory()->create(['is_active' => true, 'username' => 'coord_one']);
        $this->coordinator->assignRole('teacher');

        $this->colleague = User::factory()->create(['is_active' => true, 'username' => 'colleague_two']);
        $this->colleague->assignRole('teacher');
    }

    private function assign(User $as, User $whom)
    {
        return $this->actingAs($as)
            ->post(route('courses.teachers.store', $this->course), ['user_id' => $whom->id]);
    }

    private function isTeacher(User $user): bool
    {
        return Enrollment::query()
            ->where('course_id', $this->course->id)
            ->where('user_id', $user->id)
            ->where('role_on_course', Enrollment::ROLE_TEACHER)
            ->exists();
    }

    public function test_a_non_admin_with_manage_teachers_can_assign_themselves(): void
    {
        $this->assign($this->coordinator, $this->coordinator)->assertRedirect();

        $this->assertTrue($this->isTeacher($this->coordinator));
    }

    public function test_without_manage_teachers_nobody_can_add_themselves(): void
    {
        // Manage Materials alone is not enough: the assign route is behind
        // Manage Teachers, and the tab does not open without it either.
        Role::firstOrCreate(['name' => 'plain-teacher', 'guard_name' => 'web'])->syncPermissions(['sections.manage']);
        $teacher = User::factory()->create(['is_active' => true, 'username' => 'plain_one']);
        $teacher->assignRole('plain-teacher');

        $this->assign($teacher, $teacher)->assertForbidden();
        $this->assertFalse($this->isTeacher($teacher));

        $this->actingAs($teacher)
            ->get(route('courses.edit', ['course' => $this->course, 'tab' => 'teachers']))
            ->assertForbidden();
    }

    public function test_a_non_admin_can_still_assign_a_colleague(): void
    {
        $this->assign($this->coordinator, $this->colleague)->assertRedirect();

        $this->assertTrue($this->isTeacher($this->colleague));
    }

    public function test_an_admin_can_assign_themselves(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->assign($admin, $admin)->assertRedirect();

        $this->assertTrue($this->isTeacher($admin));
    }

    private function enrol(User $user): void
    {
        Enrollment::create([
            'course_id' => $this->course->id, 'user_id' => $user->id,
            'role_on_course' => Enrollment::ROLE_TEACHER, 'is_active' => true, 'enrolled_at' => now(),
        ]);
    }

    private function remove(User $as, User $whom)
    {
        return $this->actingAs($as)
            ->delete(route('courses.teachers.destroy', [$this->course, $whom]));
    }

    public function test_a_non_admin_cannot_remove_themselves(): void
    {
        $this->enrol($this->coordinator);

        $this->remove($this->coordinator, $this->coordinator)
            ->assertForbidden()
            ->assertSee(Course::NO_SELF_REMOVE_MESSAGE);

        $this->assertTrue($this->isTeacher($this->coordinator));
    }

    public function test_a_non_admin_can_still_remove_a_colleague(): void
    {
        $this->enrol($this->colleague);

        $this->remove($this->coordinator, $this->colleague)->assertRedirect();

        $this->assertFalse($this->isTeacher($this->colleague));
    }

    public function test_an_admin_can_remove_themselves(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->enrol($admin);

        $this->remove($admin, $admin)->assertRedirect();

        $this->assertFalse($this->isTeacher($admin));
    }

    public function test_the_tab_hides_unenroll_for_the_non_admin_themselves(): void
    {
        $this->enrol($this->coordinator);
        $this->enrol($this->colleague);

        $this->actingAs($this->coordinator)
            ->get(route('courses.edit', ['course' => $this->course, 'tab' => 'teachers']))
            ->assertOk()
            ->assertSee(route('courses.teachers.destroy', [$this->course, $this->colleague]), false)
            ->assertDontSee(route('courses.teachers.destroy', [$this->course, $this->coordinator]), false);
    }

    public function test_the_dropdown_offers_a_non_admin_themselves(): void
    {
        $page = $this->actingAs($this->coordinator)
            ->get(route('courses.edit', ['course' => $this->course, 'tab' => 'teachers']))
            ->assertOk();

        // Options render as <option value="{id}">Name (username)</option>.
        $page->assertSee('(colleague_two)</option>', false)
            ->assertSee('(coord_one)</option>', false);
    }

    public function test_the_dropdown_still_offers_an_admin_themselves(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'username' => 'admin_self']);
        $admin->assignRole('admin');

        // Admins are excluded from the candidate list by role, as before.
        $this->actingAs($admin)
            ->get(route('courses.edit', ['course' => $this->course, 'tab' => 'teachers']))
            ->assertOk()
            ->assertSee('(coord_one)</option>', false)
            ->assertSee('(colleague_two)</option>', false);
    }
}
