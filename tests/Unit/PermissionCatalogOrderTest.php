<?php

namespace Tests\Unit;

use App\Support\PermissionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The role editor lists a group's permissions in catalog order, so the
 * order here is the order an admin sees. The Users group reads as a
 * workflow: look, make, change, remove, switch on or off, then bulk in
 * and bulk out.
 */
class PermissionCatalogOrderTest extends TestCase
{
    public function test_users_permissions_are_listed_in_workflow_order(): void
    {
        $this->assertSame([
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'users.delete_student',
            'users.deactivate',
            'users.import',
            'users.export',
        ], array_keys(PermissionCatalog::GROUPS['Users']));
    }

    public function test_reordering_lost_no_users_permission(): void
    {
        // Every users.* permission the app authorises against must still
        // be offered in the editor, whatever order it comes in.
        $offered = array_keys(PermissionCatalog::GROUPS['Users']);
        sort($offered);

        $this->assertSame([
            'users.create',
            'users.deactivate',
            'users.delete',
            'users.delete_student',
            'users.edit',
            'users.export',
            'users.import',
            'users.view',
        ], $offered);
    }
}
