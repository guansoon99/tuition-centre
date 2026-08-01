@extends('layouts.app')

@section('title', 'Banner Slides')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold text-slate-900">Banner Slides</h1>
            @can('banner.create')
                <a href="{{ route('banner.create') }}"
                   class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                    + Upload
                </a>
            @endcan
        </div>

        @if ($slides->isEmpty())
            <p class="rounded-md border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
                No slides uploaded yet. The homepage shows a default banner until you add some.
            </p>
        @else
            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="w-full min-w-[720px] text-sm [&_td]:whitespace-nowrap [&_th]:whitespace-nowrap">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-800">
                        <tr>
                            <th class="px-2 py-3"></th>
                            <th class="px-4 py-3">Preview</th>
                            <th class="px-4 py-3">Title</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100" data-sortable-slides>
                        @foreach ($slides as $slide)
                            <tr data-slide-id="{{ $slide->id }}">
                                <td class="px-2 py-3 text-center">
                                    <button type="button" title="Drag to reorder"
                                            class="slide-drag-handle inline-flex h-8 w-8 cursor-grab select-none items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-700 hover:bg-slate-200 active:cursor-grabbing">
                                        {{ $loop->iteration }}
                                    </button>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="aspect-video w-32 overflow-hidden rounded bg-slate-100">
                                        <img src="{{ $slide->image_url }}"
                                             alt="" class="h-full w-full object-cover" />
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-800">
                                    {{ $slide->title ?? '(no title)' }}
                                </td>
                                <td class="px-4 py-3 font-mono text-sm">
                                    {{ $slide->created_at->format('Y-m-d H:i') }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        @can('banner.edit')
                                            <a href="{{ route('banner.edit', $slide) }}"
                                               class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-emerald-700">
                                                Edit
                                            </a>
                                        @endcan
                                        @can('banner.delete')
                                            <form method="POST" action="{{ route('banner.destroy', $slide) }}"
                                                  onsubmit="return confirm('Delete this slide?');">
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
            const list = document.querySelector('[data-sortable-slides]');
            if (!list) return;

            const renumber = () => {
                list.querySelectorAll('.slide-drag-handle')
                    .forEach((btn, i) => { btn.textContent = i + 1; });
            };

            Sortable.create(list, {
                handle: '.slide-drag-handle',
                animation: 150,
                ghostClass: 'opacity-40',
                onEnd: async () => {
                    renumber();
                    const ids = [...list.querySelectorAll('[data-slide-id]')]
                        .map(row => parseInt(row.dataset.slideId, 10));

                    try {
                        const res = await fetch('{{ route('banner.reorder') }}', {
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
