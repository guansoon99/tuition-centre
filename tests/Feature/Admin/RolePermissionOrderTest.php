<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The role editor lists each group's permissions in catalog order, so the
 * catalog is where the on-screen order is decided. This pins the Users
 * group as an admin sees it on the page, not just in the array.
 */
class RolePermissionOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_the_users_group_reads_view_create_edit_delete_delete_student_activate_import_export(): void
    {
        $this->actingAs($this->admin)
            ->get(route('roles.create'))
            ->assertOk()
            ->assertSeeInOrder([
                'value="users.view"',
                'value="users.create"',
                'value="users.edit"',
                'value="users.delete"',
                'value="users.delete_student"',
                'value="users.deactivate"',
                'value="users.import"',
                'value="users.export"',
            ], false);
    }
}
