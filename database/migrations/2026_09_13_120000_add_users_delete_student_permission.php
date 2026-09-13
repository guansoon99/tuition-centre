<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * users.delete_student: a narrower Delete. Delete removes any non-admin
 * account; this removes students only, so a role can clear out a finished
 * class without being able to remove teachers or other staff. No backfill —
 * roles that hold Delete already cover students, and admin passes every
 * permission check anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'users.delete_student', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', 'users.delete_student')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
