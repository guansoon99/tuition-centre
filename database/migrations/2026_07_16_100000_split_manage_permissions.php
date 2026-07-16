<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Split the coarse *.manage permissions for Banner, Announcement, and
 * Website Settings into finer-grained view/create/edit/delete perms.
 *
 * Existing roles that had a `.manage` permission are automatically
 * granted the full set of the new split perms so nobody loses access.
 */
return new class extends Migration
{
    private array $mapping = [
        'banner.manage'        => ['banner.view', 'banner.create', 'banner.edit', 'banner.delete'],
        'announcements.manage' => ['announcements.view', 'announcements.create', 'announcements.edit', 'announcements.delete'],
        'settings.manage'      => ['settings.view', 'settings.edit'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Create the new fine-grained permissions.
        foreach ($this->mapping as $old => $newNames) {
            foreach ($newNames as $n) {
                Permission::firstOrCreate(['name' => $n, 'guard_name' => 'web']);
            }
        }

        // For each role that currently has an old `.manage` perm, grant
        // it the whole split set, then drop the old one.
        foreach ($this->mapping as $old => $newNames) {
            $oldPerm = Permission::where('name', $old)->where('guard_name', 'web')->first();
            if (! $oldPerm) {
                continue;
            }

            foreach ($oldPerm->roles as $role) {
                foreach ($newNames as $n) {
                    $role->givePermissionTo($n);
                }
            }

            $oldPerm->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->mapping as $old => $newNames) {
            $oldPerm = Permission::firstOrCreate(['name' => $old, 'guard_name' => 'web']);

            // Give the old perm to any role that had any of the split perms.
            $roles = Role::query()
                ->whereHas('permissions', fn ($q) => $q->whereIn('name', $newNames))
                ->get();

            foreach ($roles as $role) {
                $role->givePermissionTo($oldPerm);
            }

            // Delete the split perms.
            Permission::whereIn('name', $newNames)->where('guard_name', 'web')->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
