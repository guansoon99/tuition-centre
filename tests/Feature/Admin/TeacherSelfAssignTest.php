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
 * Manage Teachers must not double as a key to every course. A non-admin
 * holding it could otherwise put themselves on any course and then edit
 * its materials, which would make the "teacher on this course" rule
 * optional for exactly the people it is meant to scope.
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

    public function test_a_non_admin_cannot_assign_themselves(): void
    {
        $this->assign($this->coordinator, $this->coordinator)
            ->assertForbidden()
            ->assertSee(Course::NO_SELF_ASSIGN_MESSAGE);

        $this->assertFalse($this->isTeacher($this->coordinator));
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

    public function test_the_dropdown_does_not_offer_a_non_admin_themselves(): void
    {
        $page = $this->actingAs($this->coordinator)
            ->get(route('courses.edit', ['course' => $this->course, 'tab' => 'teachers']))
            ->assertOk();

        // Options render as <option value="{id}">Name (username)</option>.
        $page->assertSee('(colleague_two)</option>', false)
            ->assertDontSee('(coord_one)</option>', false);
    }

    public function test_the_dropdown_still_offers_an_admin_themselves(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'username' => 'admin_self']);
        $admin->assignRole('admin');

        // Admins are excluded from the candidate list by role, as before;
        // this guards the narrower claim that the new filter did not remove
        // anyone it should not have.
        $this->actingAs($admin)
            ->get(route('courses.edit', ['course' => $this->course, 'tab' => 'teachers']))
            ->assertOk()
            ->assertSee('(coord_one)</option>', false)
            ->assertSee('(colleague_two)</option>', false);
    }
}
