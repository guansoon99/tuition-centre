@extends('layouts.app')

@section('title', 'Access logs')

@section('content')
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Access logs</h1>
            <p class="text-sm text-slate-500">{{ $logs->total() }} entries</p>
        </div>

        <form method="GET" action="{{ route('access-logs.index') }}"
              class="flex flex-wrap gap-3 rounded-md border border-slate-200 bg-white p-3">
            <input type="text" name="q" placeholder="Search user (username or name)"
                   value="{{ $filters['q'] ?? '' }}"
                   class="flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
            <select name="action" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                <option value="">Any action</option>
                <option value="view" @selected(($filters['action'] ?? '') === 'view')>view</option>
                <option value="download" @selected(($filters['action'] ?? '') === 'download')>download</option>
            </select>
            <button type="submit" class="rounded-md bg-slate-700 px-3 py-1.5 text-sm text-white">Filter</button>
            <a href="{{ route('access-logs.index') }}" class="rounded-md bg-red-500 px-3 py-1.5 text-sm text-white hover:bg-red-600">Clear</a>
        </form>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="w-full min-w-[700px] text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">When</th>
                        <th class="px-4 py-3">User</th>
                        <th class="px-4 py-3">Material</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($logs as $log)
                        <tr>
                            <td class="px-4 py-2 text-xs text-slate-500">{{ $log->accessed_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $log->user?->username ?? '—' }}</td>
                            <td class="px-4 py-2">{{ \Illuminate\Support\Str::limit($log->material?->title ?? '—', 50) }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded px-2 py-0.5 text-xs
                                    {{ $log->action === 'download' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">
                                    {{ $log->action }}
                                </span>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ $log->ip_address }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-slate-400">No log entries.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $logs->links() }}</div>
    </div>
@endsection
