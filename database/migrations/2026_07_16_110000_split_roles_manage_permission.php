<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Split the coarse `roles.manage` permission into fine-grained
 * roles.view / roles.create / roles.edit / roles.delete.
 *
 * Existing roles that had `roles.manage` are granted the full set of
 * the new split perms so nobody loses access.
 */
return new class extends Migration
{
    private array $newPerms = ['roles.view', 'roles.create', 'roles.edit', 'roles.delete'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->newPerms as $n) {
            Permission::firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }

        $old = Permission::where('name', 'roles.manage')->where('guard_name', 'web')->first();
        if ($old) {
            foreach ($old->roles as $role) {
                foreach ($this->newPerms as $n) {
                    $role->givePermissionTo($n);
                }
            }
            $old->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $old = Permission::firstOrCreate(['name' => 'roles.manage', 'guard_name' => 'web']);

        $roles = Role::query()
            ->whereHas('permissions', fn ($q) => $q->whereIn('name', $this->newPerms))
            ->get();

        foreach ($roles as $role) {
            $role->givePermissionTo($old);
        }

        Permission::whereIn('name', $this->newPerms)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
