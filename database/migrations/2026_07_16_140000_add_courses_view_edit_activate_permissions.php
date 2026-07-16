<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Add courses.view / courses.edit / courses.activate. Backfills
 * courses.view to any role that already had a course-management perm
 * (manage_teachers, manage_students, sections.manage) so nobody loses
 * access to the Courses list. Edit + Activate were admin-only before
 * so they don't need back-fill.
 */
return new class extends Migration
{
    private array $newPerms = ['courses.view', 'courses.edit', 'courses.activate'];

    private array $manageEquivalents = [
        'courses.manage_teachers',
        'courses.manage_students',
        'sections.manage',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->newPerms as $n) {
            Permission::firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }

        // Any role with any manage-equivalent perm gets courses.view so
        // it can still open the courses list.
        $rolesToGrant = Permission::whereIn('name', $this->manageEquivalents)
            ->where('guard_name', 'web')
            ->with('roles')
            ->get()
            ->flatMap(fn ($p) => $p->roles)
            ->unique('id');

        foreach ($rolesToGrant as $role) {
            $role->givePermissionTo('courses.view');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', $this->newPerms)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
