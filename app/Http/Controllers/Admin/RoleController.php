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

    public function edit(Request $request, Role $role): View|RedirectResponse
    {
        if ($this->isOwnRole($request->user(), $role)) {
            return redirect()
                ->route('roles.index')
                ->withErrors(['role' => "You can't edit a role you currently hold ('{$role->name}')."]);
        }

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
        if ($this->isOwnRole($request->user(), $role)) {
            return redirect()
                ->route('roles.index')
                ->withErrors(['role' => "You can't edit a role you currently hold ('{$role->name}')."]);
        }

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

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        if ($this->isOwnRole($request->user(), $role)) {
            return back()->withErrors(['role' => "You can't delete a role you currently hold ('{$role->name}')."]);
        }

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

    /**
     * True if $user currently holds $role — used to block a user from
     * editing or deleting the role they themselves depend on. Admins are
     * exempt (they have their own bypass and won't lock themselves out).
     */
    private function isOwnRole(?\App\Models\User $user, Role $role): bool
    {
        if (! $user || $user->hasRole('admin')) {
            return false;
        }
        return $user->hasRole($role->name);
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
