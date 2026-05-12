<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Seed all permissions from the catalog.
        foreach (PermissionCatalog::allPermissionNames() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Seed the baseline roles (system + teacher).
        foreach (['admin', 'teacher', 'student'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Admin gets nothing assigned — `Gate::before` in AuthServiceProvider
        // makes admin auto-pass any can() check.

        // Teacher: can edit content + enroll students in their assigned courses.
        // Per-course scoping is enforced inside the policies/controllers.
        Role::findByName('teacher', 'web')->syncPermissions([
            'sections.manage',
            'courses.manage_students',
        ]);

        // Student: no permissions; access is via enrollments.
        Role::findByName('student', 'web')->syncPermissions([]);
    }
}
