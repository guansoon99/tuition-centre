<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Add a dedicated `users.deactivate` permission. Any role that already
 * had `users.edit` gets the new perm automatically (before this split,
 * the Deactivate/Activate buttons were only shown to users with `edit`).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'users.deactivate', 'guard_name' => 'web']);

        $edit = Permission::where('name', 'users.edit')->where('guard_name', 'web')->first();
        if ($edit) {
            foreach ($edit->roles as $role) {
                $role->givePermissionTo('users.deactivate');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', 'users.deactivate')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
