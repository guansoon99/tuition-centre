@extends('layouts.app')

@section('title', 'Announcement')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold text-slate-900">Announcements</h1>
            @can('announcements.create')
                <a href="{{ route('announcements.create') }}"
                   class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                    + Send Announcement
                </a>
            @endcan
        </div>

        @if ($announcements->isEmpty())
            <p class="rounded-md border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
                No announcements sent yet.
            </p>
        @else
            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="w-full min-w-[820px] text-sm [&_td]:whitespace-nowrap [&_th]:whitespace-nowrap">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-800">
                        <tr>
                            <th class="px-4 py-3">Title</th>
                            <th class="px-4 py-3">Audience</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Start</th>
                            <th class="px-4 py-3">End</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($announcements as $a)
                            @php
                                $now = now();
                                $starts = $a->starts_at ? \Carbon\Carbon::parse($a->starts_at) : null;
                                $ends = $a->ends_at ? \Carbon\Carbon::parse($a->ends_at) : null;
                                $status = match (true) {
                                    $ends && $now->gt($ends) => 'expired',
                                    $starts && $now->lt($starts) => 'scheduled',
                                    default => 'active',
                                };
                            @endphp
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="text-slate-800">{{ $a->title }}</p>
                                </td>
                                <td class="px-4 py-3 text-slate-800">{{ $a->audience_label ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($status === 'active')
                                        <span class="inline-flex min-w-[84px] items-center justify-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">
                                            <span class="mr-1 h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Active
                                        </span>
                                    @elseif ($status === 'scheduled')
                                        <span class="inline-flex min-w-[84px] items-center justify-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                                            <span class="mr-1 h-1.5 w-1.5 rounded-full bg-amber-500"></span>Scheduled
                                        </span>
                                    @else
                                        <span class="inline-flex min-w-[84px] items-center justify-center rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600">
                                            <span class="mr-1 h-1.5 w-1.5 rounded-full bg-slate-500"></span>Expired
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-mono text-sm">
                                    {{ $starts ? $starts->format('Y-m-d H:i') : '—' }}
                                </td>
                                <td class="px-4 py-3 font-mono text-sm">
                                    {{ $ends ? $ends->format('Y-m-d H:i') : '—' }}
                                </td>
                                <td class="px-4 py-3 font-mono text-sm">
                                    {{ $a->sent_at?->format('Y-m-d H:i') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <a href="{{ route('announcements.show', $a->id) }}"
                                           class="rounded-md bg-sky-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-sky-700">
                                            View
                                        </a>
                                        @can('announcements.edit')
                                            <a href="{{ route('announcements.edit', $a->id) }}"
                                               class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-emerald-700">
                                                Edit
                                            </a>
                                        @endcan
                                        @can('announcements.delete')
                                            <form method="POST" action="{{ route('announcements.destroy', $a->id) }}"
                                                  onsubmit="return confirm('Delete this announcement? It will disappear from all recipients.');">
                                                @csrf @method('DELETE')
                                                <button type="submit"
                                                        class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-red-700">
                                                    Delete
                                                </button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
