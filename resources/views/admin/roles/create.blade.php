@extends('layouts.app')

@section('title', $role ? 'Edit role' : 'New role')

@section('content')
    @php
        $isSystem = $role && in_array($role->name, \App\Support\PermissionCatalog::SYSTEM_ROLES, true);
        $action = $role ? route('roles.update', $role) : route('roles.store');
    @endphp

    <div class="mx-auto max-w-3xl space-y-4">
        <div>
            <a href="{{ route('roles.index') }}" class="text-xs text-slate-500 hover:underline">&larr; All roles</a>
            <h1 class="mt-2 text-xl font-semibold text-slate-900">
                {{ $role ? 'Edit '.ucfirst($role->name) : 'New role' }}
            </h1>
        </div>

        <form method="POST" action="{{ $action }}" class="space-y-4">
            @csrf
            @if ($role)
                @method('PATCH')
            @endif

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Name</label>
                <input type="text" name="name" required maxlength="64"
                       value="{{ old('name', $role?->name) }}"
                       @disabled($isSystem)
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm @if ($isSystem) cursor-not-allowed bg-slate-100 text-slate-700 @endif" />
                @if ($isSystem)
                    <p class="mt-1 text-xs text-slate-500">System role names cannot be renamed.</p>
                @endif
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Permissions</label>

                @if ($isSystem)
                    <p class="mb-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                        System role permissions are locked and cannot be edited from this page.
                    </p>
                @endif

                <div class="space-y-5 rounded-md border border-slate-200 bg-white p-4 @if ($isSystem) opacity-70 @endif">
                    @foreach ($permissionGroups as $group => $perms)
                        <div>
                            <p class="mb-2 text-xs font-bold uppercase tracking-[0.15em] text-slate-500">{{ $group }}</p>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach ($perms as $perm => $label)
                                    <label class="flex items-start gap-2 text-sm text-slate-700 @if ($isSystem) cursor-not-allowed @endif">
                                        <input type="checkbox" name="permissions[]" value="{{ $perm }}"
                                               @checked(in_array($perm, old('permissions', $selected), true))
                                               @disabled($isSystem)
                                               class="mt-0.5">
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                @error('permissions') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-3">
                @unless ($isSystem)
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                        {{ $role ? 'Save changes' : 'Create role' }}
                    </button>
                @endunless
                <a href="{{ route('roles.index') }}"
                   class="rounded-md @if ($isSystem) border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 @else bg-red-600 text-white hover:bg-red-700 @endif px-4 py-2 text-sm font-medium shadow-sm">
                    @if ($isSystem) Back @else Cancel @endif
                </a>
            </div>
        </form>
    </div>
@endsection
