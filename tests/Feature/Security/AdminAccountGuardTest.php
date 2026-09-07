<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin accounts can only be changed by admins.
 *
 * Route permissions say what a role may do and nothing about whom. Without a
 * target check, any role granted users.deactivate could switch every admin
 * off, and one granted users.edit would reach the edit form for an admin
 * account. The guard lives in UserController::assertMayManage() and is
 * applied to edit, update, destroy and activate; bulkDestroy skips admins on
 * its own.
 *
 * The "staff" role below holds every users.* permission short of admin,
 * which is the strongest position a non-admin can be put in.
 */
class AdminAccountGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web'])
            ->givePermissionTo(['users.view', 'users.edit', 'users.deactivate', 'users.delete']);

        $this->staff = User::factory()->create(['is_active' => true, 'username' => 'staff1']);
        $this->staff->assignRole('staff');

        $this->admin = User::factory()->create(['is_active' => true, 'username' => 'admin1']);
        $this->admin->assignRole('admin');

        $this->student = User::factory()->create(['is_active' => true, 'username' => 'stu1']);
        $this->student->assignRole('student');
    }

    private function payload(User $for): array
    {
        return [
            'username' => $for->username,
            'name' => $for->name,
            'role' => $for->roles->first()->name,
            'is_active' => '1',
        ];
    }

    // ---- A non-admin cannot touch an admin account --------------------------

    public function test_staff_cannot_open_the_edit_form_for_an_admin(): void
    {
        $this->actingAs($this->staff)
            ->get(route('users.edit', $this->admin))
            ->assertForbidden();
    }

    public function test_staff_cannot_update_an_admin(): void
    {
        $this->actingAs($this->staff)
            ->patch(route('users.update', $this->admin), array_merge($this->payload($this->admin), [
                'name' => 'Hijacked',
                'password' => 'newpassword1',
                'password_confirmation' => 'newpassword1',
            ]))
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $this->admin->fresh()->name);
    }

    /**
     * The one that mattered most: with users.deactivate alone, staff could
     * have switched off every admin and locked the site.
     */
    public function test_staff_cannot_deactivate_an_admin(): void
    {
        $this->actingAs($this->staff)
            ->delete(route('users.destroy', $this->admin))
            ->assertForbidden();

        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_staff_cannot_reactivate_an_admin(): void
    {
        $this->admin->update(['is_active' => false]);

        $this->actingAs($this->staff)
            ->post(route('users.activate', $this->admin))
            ->assertForbidden();

        $this->assertFalse($this->admin->fresh()->is_active);
    }

    // ---- The permissions still work on non-admin accounts -------------------

    /** The guard is about the target, not the actor's rights in general. */
    public function test_staff_can_still_deactivate_and_reactivate_a_student(): void
    {
        $this->actingAs($this->staff)
            ->delete(route('users.destroy', $this->student))
            ->assertRedirect();
        $this->assertFalse($this->student->fresh()->is_active);

        $this->actingAs($this->staff)
            ->post(route('users.activate', $this->student))
            ->assertRedirect();
        $this->assertTrue($this->student->fresh()->is_active);
    }

    public function test_staff_can_still_open_the_edit_form_for_a_student(): void
    {
        $this->actingAs($this->staff)
            ->get(route('users.edit', $this->student))
            ->assertOk();
    }

    // ---- Admins are unaffected ----------------------------------------------

    public function test_an_admin_can_still_deactivate_another_admin(): void
    {
        $other = User::factory()->create(['is_active' => true, 'username' => 'admin2']);
        $other->assignRole('admin');

        $this->actingAs($this->admin)
            ->delete(route('users.destroy', $other))
            ->assertRedirect();

        $this->assertFalse($other->fresh()->is_active);
    }

    public function test_an_admin_can_still_edit_another_admin(): void
    {
        $other = User::factory()->create(['is_active' => true, 'username' => 'admin2']);
        $other->assignRole('admin');

        $this->actingAs($this->admin)
            ->patch(route('users.update', $other), array_merge($this->payload($other), ['name' => 'Renamed']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $other->fresh()->name);
    }
}
