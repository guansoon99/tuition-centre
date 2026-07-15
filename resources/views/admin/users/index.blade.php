@extends('layouts.app')

@section('title', 'Users')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold text-slate-900">Users</h1>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('users.export', request()->only(['q', 'role', 'active', 'course'])) }}"
                   class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700">
                    Export Excel
                </a>
                @can('users.create')
                    <a href="{{ route('users.create') }}"
                       class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                        + New User
                    </a>
                @endcan
            </div>
        </div>

        <form method="GET" action="{{ route('users.index') }}"
              x-data
              x-init="
                  if (sessionStorage.getItem('users-q-focus') === '1') {
                      sessionStorage.removeItem('users-q-focus');
                      const input = $el.querySelector('input[name=q]');
                      if (input) {
                          input.focus();
                          input.setSelectionRange(input.value.length, input.value.length);
                      }
                  }
              "
              class="flex flex-wrap gap-3 rounded-md border border-slate-200 bg-white p-3">
            <input type="text" name="q" placeholder="Search username or name"
                   value="{{ $filters['q'] ?? '' }}"
                   @input.debounce.500ms="sessionStorage.setItem('users-q-focus', '1'); $el.form.submit()"
                   class="flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />

            <select name="role" onchange="this.form.submit()"
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                <option value="">All Roles</option>
                @foreach (\Spatie\Permission\Models\Role::where('name', '!=', 'admin')->orderBy('name')->pluck('name') as $r)
                    <option value="{{ $r }}" @selected(($filters['role'] ?? '') === $r)>{{ ucfirst($r) }}</option>
                @endforeach
            </select>

            <select name="course" onchange="this.form.submit()"
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                <option value="">All Courses</option>
                @foreach (\App\Models\Course::orderByDesc('created_at')->get(['id', 'name', 'code']) as $c)
                    <option value="{{ $c->id }}" @selected((string) ($filters['course'] ?? '') === (string) $c->id)>{{ $c->code }} — {{ $c->name }}</option>
                @endforeach
            </select>

            <select name="active" onchange="this.form.submit()"
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                <option value="">All Status</option>
                <option value="1" @selected(($filters['active'] ?? '') === '1')>Active</option>
                <option value="0" @selected(($filters['active'] ?? '') === '0')>Inactive</option>
            </select>

            <a href="{{ route('users.index') }}" class="rounded-md bg-red-500 px-3 py-1.5 text-sm text-white hover:bg-red-600">Clear</a>
        </form>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="w-full min-w-[700px] text-sm [&_td]:whitespace-nowrap [&_th]:whitespace-nowrap">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-800">
                    <tr>
                        <th class="px-4 py-3">Username</th>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Active</th>
                        <th class="px-4 py-3">Last login</th>
                        <th class="px-4 py-3">Created</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($users as $u)
                        <tr>
                            <td class="px-4 py-3 font-mono text-sm text-slate-800">{{ $u->username }}</td>
                            <td class="px-4 py-3 text-slate-800">{{ $u->name }}</td>
                            <td class="px-4 py-3 text-slate-800">
                                @php $role = $u->roles->first()?->name; @endphp
                                {{ $role ? ucfirst($role) : '—' }}
                            </td>
                            <td class="px-4 py-3">
                                @if ($u->is_active)
                                    <span class="inline-flex min-w-[72px] items-center justify-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">
                                        <span class="mr-1 h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                        Active
                                    </span>
                                @else
                                    <span class="inline-flex min-w-[72px] items-center justify-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                        <span class="mr-1 h-1.5 w-1.5 rounded-full bg-red-500"></span>
                                        Inactive
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-sm">
                                {{ $u->last_login_at?->format('Y-m-d H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 font-mono text-sm">
                                {{ $u->created_at->format('Y-m-d H:i') }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('users.show', $u) }}"
                                       class="rounded-md bg-sky-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-sky-700">
                                        View
                                    </a>
                                    @can('users.edit')
                                        <a href="{{ route('users.edit', $u) }}"
                                           class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-emerald-700">
                                            Edit
                                        </a>
                                        @if ($u->id !== auth()->id())
                                            @if ($u->is_active)
                                                <form method="POST" action="{{ route('users.destroy', $u) }}"
                                                      onsubmit="return confirm('Deactivate {{ $u->username }}? They won\'t be able to log in.');">
                                                    @csrf @method('DELETE')
                                                    <button type="submit"
                                                            class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-red-700">
                                                        Deactivate
                                                    </button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('users.activate', $u) }}">
                                                    @csrf
                                                    <button type="submit"
                                                            class="min-w-[88px] rounded-md bg-amber-500 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-amber-600">
                                                        Activate
                                                    </button>
                                                </form>
                                            @endif
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-400">No users match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $users->links() }}</div>
    </div>
@endsection
