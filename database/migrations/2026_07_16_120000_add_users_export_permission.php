<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Add a dedicated `users.export` permission. Preserves existing behaviour
 * by granting it automatically to every role that already had `users.view`
 * (which is where the Export button used to sit unguarded).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'users.export', 'guard_name' => 'web']);

        $view = Permission::where('name', 'users.view')->where('guard_name', 'web')->first();
        if ($view) {
            foreach ($view->roles as $role) {
                $role->givePermissionTo('users.export');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', 'users.export')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
