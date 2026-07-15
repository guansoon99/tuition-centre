@extends('layouts.app')

@section('title', 'Roles')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold text-slate-900">Roles</h1>
            <a href="{{ route('roles.create') }}"
               class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                + New role
            </a>
        </div>

        @if ($errors->any())
            <div class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                @foreach ($errors->all() as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="w-full min-w-[700px] text-sm [&_td]:whitespace-nowrap [&_th]:whitespace-nowrap">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-800">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3 text-right">Permissions</th>
                        <th class="px-4 py-3 text-right">Users</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Created</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($roles as $role)
                        @php $isSystem = in_array($role->name, $systemRoles, true); @endphp
                        <tr>
                            <td class="px-4 py-3 text-slate-800">{{ ucfirst($role->name) }}</td>
                            <td class="px-4 py-3 text-right font-mono text-sm text-slate-800">{{ $role->permissions_count }}</td>
                            <td class="px-4 py-3 text-right font-mono text-sm text-slate-800">{{ $role->users_count }}</td>
                            <td class="px-4 py-3">
                                @if ($isSystem)
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                        System
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-700">
                                        Custom
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-sm">
                                {{ $role->created_at->format('Y-m-d H:i') }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('roles.edit', $role) }}"
                                       class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-emerald-700">
                                        Edit
                                    </a>
                                    @unless ($isSystem)
                                        <form method="POST" action="{{ route('roles.destroy', $role) }}"
                                              onsubmit="return confirm('Delete role {{ $role->name }}? This cannot be undone.');">
                                            @csrf @method('DELETE')
                                            <button type="submit"
                                                    class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-red-700">
                                                Delete
                                            </button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-slate-400">No roles.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
