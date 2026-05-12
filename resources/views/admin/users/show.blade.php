@extends('layouts.app')

@section('title', 'View user')

@section('content')
    @php $role = $user->roles->first()?->name; @endphp

    <div class="mx-auto max-w-3xl space-y-4">
        <div>
            <a href="{{ route('users.index') }}" class="text-xs text-slate-500 hover:underline">&larr; All users</a>
            <div class="mt-2 flex items-center justify-between gap-3">
                <h1 class="text-xl font-semibold text-slate-900">View user</h1>
                @if ($user->is_active)
                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                        <span class="mr-1.5 h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Active
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">
                        <span class="mr-1.5 h-1.5 w-1.5 rounded-full bg-red-500"></span>Inactive
                    </span>
                @endif
            </div>
        </div>

        <div class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Username</label>
                    <input type="text" value="{{ $user->username }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Name</label>
                    <input type="text" value="{{ $user->name }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Phone</label>
                    <input type="text" value="{{ $user->phone ?: '—' }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">IC Number</label>
                    <input type="text" value="{{ $user->ic_number ?: '—' }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-900" />
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">Candidate Number</label>
                    <input type="text" value="{{ $user->candidate_number ?: '—' }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-900" />
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">Role</label>
                    <input type="text" value="{{ $role ? ucfirst($role) : '—' }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-900" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Last login</label>
                    <input type="text" value="{{ $user->last_login_at?->format('Y-m-d H:i') ?? '—' }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 font-mono text-sm text-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Created</label>
                    <input type="text" value="{{ $user->created_at->format('Y-m-d H:i') }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 font-mono text-sm text-slate-900" />
                </div>
            </div>

            <div class="flex gap-3 pt-2">
                <a href="{{ route('users.edit', $user) }}"
                   class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700">
                    Edit
                </a>
                @if ($user->id !== auth()->id())
                    @if ($user->is_active)
                        <form method="POST" action="{{ route('users.destroy', $user) }}"
                              onsubmit="return confirm('Deactivate {{ $user->username }}? They won\'t be able to log in.');">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
                                Deactivate
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('users.activate', $user) }}">
                            @csrf
                            <button type="submit"
                                    class="min-w-[108px] rounded-md bg-amber-500 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-600">
                                Activate
                            </button>
                        </form>
                    @endif
                @endif
                <a href="{{ route('users.index') }}"
                   class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                    Back
                </a>
            </div>
        </div>
    </div>
@endsection
