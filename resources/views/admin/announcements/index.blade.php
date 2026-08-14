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
                            <th class="px-2 py-3"></th>
                            <th class="px-4 py-3">Title</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Audience</th>
                            <th class="px-4 py-3">Course</th>
                            <th class="px-4 py-3">Start</th>
                            <th class="px-4 py-3">End</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100" data-sortable-announcements>
                        @foreach ($announcements as $a)
                            @php
                                $starts = $a->starts_at ? \Carbon\Carbon::parse($a->starts_at) : null;
                                $ends = $a->ends_at ? \Carbon\Carbon::parse($a->ends_at) : null;
                            @endphp
                            <tr data-announcement-id="{{ $a->id }}">
                                <td class="px-2 py-3 text-center">
                                    <button type="button" title="Drag to reorder"
                                            class="announcement-drag-handle inline-flex h-8 w-8 cursor-grab select-none items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-700 hover:bg-slate-200 active:cursor-grabbing">
                                        {{ $loop->iteration }}
                                    </button>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="text-slate-800">{{ $a->title }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                        {{ \App\Models\Announcement::TYPES[$a->type] ?? ucfirst($a->type) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-800">{{ $a->audience_label ?: '—' }}</td>
                                <td class="px-4 py-3 text-slate-800">
                                    @if ($a->course)
                                        {{ $a->course->code }} — {{ \Illuminate\Support\Str::limit($a->course->name, 40) }}
                                    @else
                                        All
                                    @endif
                                </td>
                                {{-- Blank means unbounded: no start is live
                                     immediately, no end runs until removed.
                                     The create form spells that out; here it is
                                     just a dash, matching the other columns. --}}
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">
                                    {{ $starts?->format('Y-m-d H:i') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 font-mono text-sm text-slate-800">
                                    {{ $ends?->format('Y-m-d H:i') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 font-mono text-sm">
                                    {{ $a->sent_at?->format('Y-m-d H:i') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex justify-end gap-2">
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

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const list = document.querySelector('[data-sortable-announcements]');
            if (!list) return;

            const renumber = () => {
                list.querySelectorAll('.announcement-drag-handle')
                    .forEach((btn, i) => { btn.textContent = i + 1; });
            };

            Sortable.create(list, {
                handle: '.announcement-drag-handle',
                animation: 150,
                ghostClass: 'opacity-40',
                onEnd: async () => {
                    renumber();
                    const ids = [...list.querySelectorAll('[data-announcement-id]')]
                        .map(row => parseInt(row.dataset.announcementId, 10));

                    try {
                        const res = await fetch('{{ route('announcements.reorder') }}', {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({ ids }),
                        });
                        if (!res.ok) throw new Error('Save failed (' + res.status + ')');
                    } catch (e) {
                        alert('Could not save new order: ' + e.message);
                    }
                },
            });
        });
    </script>
@endpush
