@extends('layouts.app')

@section('title', 'Banner slides')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold text-slate-900">Banner slides</h1>
            <a href="{{ route('banner.create') }}"
               class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                + Upload
            </a>
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
                            <th class="px-4 py-3">Preview</th>
                            <th class="px-4 py-3">Title</th>
                            <th class="px-4 py-3 text-center">Order</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($slides as $slide)
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="aspect-video w-32 overflow-hidden rounded bg-slate-100">
                                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($slide->image_path) }}"
                                             alt="" class="h-full w-full object-cover" />
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-800">
                                    {{ $slide->title ?? '(no title)' }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-slate-100 font-mono text-sm font-bold text-slate-700">
                                        {{ $slide->sort_order }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($slide->is_active)
                                        <span class="inline-flex min-w-[72px] items-center justify-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">
                                            <span class="mr-1 h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Active
                                        </span>
                                    @else
                                        <span class="inline-flex min-w-[72px] items-center justify-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                            <span class="mr-1 h-1.5 w-1.5 rounded-full bg-red-500"></span>Inactive
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <a href="{{ route('banner.show', $slide) }}"
                                           class="rounded-md bg-sky-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-sky-700">
                                            View
                                        </a>
                                        <a href="{{ route('banner.edit', $slide) }}"
                                           class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-emerald-700">
                                            Edit
                                        </a>
                                        <form method="POST" action="{{ route('banner.destroy', $slide) }}"
                                              onsubmit="return confirm('Delete this slide?');">
                                            @csrf @method('DELETE')
                                            <button type="submit"
                                                    class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-red-700">
                                                Delete
                                            </button>
                                        </form>
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
