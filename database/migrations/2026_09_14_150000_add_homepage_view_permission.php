<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * homepage.view: opens the homepage in the back office as visitors see it,
 * without the editing controls. Every role that already holds homepage.edit
 * is given it too, so nobody loses the page from their sidebar.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $view = Permission::firstOrCreate(['name' => 'homepage.view', 'guard_name' => 'web']);
        Role::permission('homepage.edit')->get()->each(fn (Role $role) => $role->givePermissionTo($view));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', 'homepage.view')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
