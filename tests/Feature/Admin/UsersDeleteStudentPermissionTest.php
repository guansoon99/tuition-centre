<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Delete Student is the narrow sibling of Delete. Delete reaches every
 * non-admin account; Delete Student reaches accounts that are a student and
 * nothing else. A registrar-type role can then clear out a finished class
 * without being able to remove teachers.
 */
class UsersDeleteStudentPermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;

    private User $student;

    private User $teacher;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        foreach (['users.view', 'users.delete', 'users.delete_student'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        // A custom role holding only the narrow permission.
        Role::firstOrCreate(['name' => 'registrar', 'guard_name' => 'web'])
            ->syncPermissions(['users.view', 'users.delete_student']);

        $this->registrar = User::factory()->create(['is_active' => true]);
        $this->registrar->assignRole('registrar');

        $this->student = User::factory()->create(['is_active' => true, 'username' => 'stu_one']);
        $this->student->assignRole('student');

        $this->teacher = User::factory()->create(['is_active' => true, 'username' => 'tea_one']);
        $this->teacher->assignRole('teacher');

        $this->admin = User::factory()->create(['is_active' => true, 'username' => 'adm_one']);
        $this->admin->assignRole('admin');
    }

    public function test_the_permission_is_offered_under_users_right_after_delete(): void
    {
        $this->actingAs($this->admin)
            ->get(route('roles.create'))
            ->assertOk()
            ->assertSeeInOrder(['value="users.delete"', 'Delete<', 'value="users.delete_student"', 'Delete Student<'], false);
    }

    public function test_delete_student_removes_a_student(): void
    {
        $this->actingAs($this->registrar)
            ->post(route('users.bulk-destroy'), ['ids' => [$this->student->id]])
            ->assertRedirect();

        $this->assertSoftDeleted('users', ['id' => $this->student->id]);
    }

    public function test_delete_student_leaves_teachers_and_admins_alone(): void
    {
        $this->actingAs($this->registrar)
            ->post(route('users.bulk-destroy'), ['ids' => [$this->teacher->id, $this->admin->id, $this->student->id]])
            ->assertRedirect();

        $this->assertNotSoftDeleted('users', ['id' => $this->teacher->id]);
        $this->assertNotSoftDeleted('users', ['id' => $this->admin->id]);
        // The student in the same batch still goes: the others are skipped, not the request.
        $this->assertSoftDeleted('users', ['id' => $this->student->id]);
    }

    public function test_a_student_who_is_also_something_else_is_not_just_a_student(): void
    {
        $this->student->assignRole('teacher');

        $this->actingAs($this->registrar)
            ->post(route('users.bulk-destroy'), ['ids' => [$this->student->id]])
            ->assertRedirect();

        $this->assertNotSoftDeleted('users', ['id' => $this->student->id]);
    }

    public function test_full_delete_still_removes_a_teacher(): void
    {
        Role::findByName('registrar', 'web')->syncPermissions(['users.view', 'users.delete']);

        $this->actingAs($this->registrar)
            ->post(route('users.bulk-destroy'), ['ids' => [$this->teacher->id]])
            ->assertRedirect();

        $this->assertSoftDeleted('users', ['id' => $this->teacher->id]);
    }

    public function test_neither_permission_means_no_bulk_delete_at_all(): void
    {
        Role::findByName('registrar', 'web')->syncPermissions(['users.view']);

        $this->actingAs($this->registrar)
            ->post(route('users.bulk-destroy'), ['ids' => [$this->student->id]])
            ->assertForbidden();

        $this->assertNotSoftDeleted('users', ['id' => $this->student->id]);
    }

    public function test_the_list_offers_a_checkbox_on_student_rows_only(): void
    {
        $html = $this->actingAs($this->registrar)->get(route('users.index'))->assertOk()->getContent();

        $this->assertStringContainsString('x-model="selected" value="'.$this->student->id.'"', $html);
        $this->assertStringNotContainsString('x-model="selected" value="'.$this->teacher->id.'"', $html);
        $this->assertStringNotContainsString('x-model="selected" value="'.$this->admin->id.'"', $html);
        // The Delete button and the bulk form are still there for the boxes that do exist.
        $this->assertStringContainsString(route('users.bulk-destroy'), $html);
    }

    public function test_full_delete_offers_a_checkbox_on_every_row(): void
    {
        Role::findByName('registrar', 'web')->syncPermissions(['users.view', 'users.delete']);

        $html = $this->actingAs($this->registrar)->get(route('users.index'))->assertOk()->getContent();

        $this->assertStringContainsString('x-model="selected" value="'.$this->student->id.'"', $html);
        $this->assertStringContainsString('x-model="selected" value="'.$this->teacher->id.'"', $html);
        // Admin accounts are never listed on /users (buildIndexQuery leaves
        // them out), so there is no admin row to check here; the controller
        // skips them regardless of who asks.
    }
}
