<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\PermissionCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function index(): View
    {
        $roles = Role::query()
            ->withCount(['permissions', 'users'])
            ->orderByDesc('created_at')
            ->get();

        return view('admin.roles.index', [
            'roles' => $roles,
            'systemRoles' => PermissionCatalog::SYSTEM_ROLES,
        ]);
    }

    public function create(): View
    {
        return view('admin.roles.create', [
            'role' => null,
            'permissionGroups' => PermissionCatalog::GROUPS,
            'selected' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $this->syncPermissions($role, $data['permissions'] ?? []);

        return redirect()
            ->route('roles.index')
            ->with('status', "Role {$role->name} created.");
    }

    public function edit(Role $role): View
    {
        // Admin role visually shows every permission ticked (it has implicit
        // access to everything regardless of what's stored on the pivot).
        $selected = $role->name === 'admin'
            ? PermissionCatalog::allPermissionNames()
            : $role->permissions->pluck('name')->all();

        return view('admin.roles.create', [
            'role' => $role,
            'permissionGroups' => PermissionCatalog::GROUPS,
            'selected' => $selected,
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validated($request, $role);

        $isSystem = in_array($role->name, PermissionCatalog::SYSTEM_ROLES, true);

        // System roles have locked names AND locked permissions — neither can
        // be changed from the UI. Updates to system roles are no-ops aside
        // from triggering the success message.
        if (! $isSystem) {
            $role->update(['name' => $data['name']]);
            $this->syncPermissions($role, $data['permissions'] ?? []);
        }

        return redirect()
            ->route('roles.index')
            ->with('status', "Role {$role->name} updated.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        if (in_array($role->name, PermissionCatalog::SYSTEM_ROLES, true)) {
            return back()->withErrors(['role' => "System role '{$role->name}' cannot be deleted."]);
        }

        if ($role->users()->exists()) {
            return back()->withErrors(['role' => "Role '{$role->name}' is assigned to users — reassign them first."]);
        }

        $name = $role->name;
        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()
            ->route('roles.index')
            ->with('status', "Role {$name} deleted.");
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-zA-Z0-9_\- ]+$/',
                Rule::unique('roles', 'name')->ignore($role?->id),
            ],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::allPermissionNames())],
        ]);
    }

    /**
     * Ensure each ticked permission exists in the DB, then sync to the role.
     */
    private function syncPermissions(Role $role, array $names): void
    {
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $role->syncPermissions($names);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
