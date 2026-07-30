<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Remove any pre-existing events.* permissions if this migration ran
        // in an earlier iteration of the calendar feature.
        DB::table('permissions')
            ->whereIn('name', ['events.view', 'events.create', 'events.edit', 'events.delete'])
            ->where('guard_name', 'web')
            ->delete();

        foreach (['calendar.create', 'calendar.edit', 'calendar.delete'] as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('name', ['calendar.create', 'calendar.edit', 'calendar.delete'])
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
