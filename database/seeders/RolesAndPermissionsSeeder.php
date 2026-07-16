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

        // Seed only the system roles. `teacher` is created by an admin via
        // the roles UI after install so they control its name + permissions.
        foreach (['admin', 'student'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Admin gets nothing assigned — `Gate::before` in AuthServiceProvider
        // makes admin auto-pass any can() check.
        // Student: no permissions; access is via enrollments.
        Role::findByName('student', 'web')->syncPermissions([]);
    }
}
