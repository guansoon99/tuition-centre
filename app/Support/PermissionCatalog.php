<?php

namespace App\Support;

/**
 * Permission catalog for the Roles module.
 *
 * This is intentionally NOT wired into route middleware or policies yet —
 * the Roles UI just lets admins define roles + tick permissions. When the
 * permissions are actually enforced elsewhere, point those checks at the
 * names listed here.
 */
class PermissionCatalog
{
    /**
     * Permissions grouped by area. Keys = stable internal names used in code.
     * Values = human-readable labels shown in the role editor.
     *
     * @var array<string, array<string, string>>
     */
    public const GROUPS = [
        'Users' => [
            'users.view' => 'View',
            'users.create' => 'Create',
            'users.edit' => 'Edit',
            'users.import' => 'Import Students',
            'roles.manage' => 'Roles',
        ],
        'Courses' => [
            'courses.manage_teachers' => 'Manage Teachers',
            'courses.manage_students' => 'Manage Students',
            'sections.manage' => 'Manage Sections & Materials',
        ],
        'Settings' => [
            'banner.manage' => 'Manage Banners',
            'announcements.manage' => 'Manage Announcements',
            'settings.manage' => 'Manage Website Settings',
        ],
    ];

    /**
     * Roles that ship with the app and cannot be deleted from the UI.
     */
    public const SYSTEM_ROLES = ['admin', 'student'];

    /**
     * Flat list of all permission internal names.
     *
     * @return list<string>
     */
    public static function allPermissionNames(): array
    {
        $names = [];
        foreach (self::GROUPS as $perms) {
            foreach ($perms as $name => $label) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
