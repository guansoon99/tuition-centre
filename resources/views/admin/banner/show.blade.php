@extends('layouts.app')

@section('title', 'View banner slide')

@section('content')
    <div class="mx-auto max-w-3xl space-y-4">
        <div>
            <a href="{{ route('banner.index') }}" class="text-xs text-slate-500 hover:underline">&larr; All banner slides</a>
            <div class="mt-2 flex items-center justify-between gap-3">
                <h1 class="text-xl font-semibold text-slate-900">View banner slide</h1>
                @if ($slide->is_active)
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
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Image</label>
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($slide->image_path) }}"
                     alt="" class="h-40 rounded border border-slate-200 object-contain" />
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Title</label>
                <input type="text" value="{{ $slide->title ?: '—' }}" disabled
                       class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-900" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Order</label>
                    <input type="text" value="#{{ $slide->sort_order }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 font-mono text-sm text-slate-900" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Uploaded</label>
                    <input type="text" value="{{ $slide->created_at->format('Y-m-d H:i') }}" disabled
                           class="w-full cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 font-mono text-sm text-slate-900" />
                </div>
            </div>

            <div class="flex gap-3 pt-2">
                <a href="{{ route('banner.edit', $slide) }}"
                   class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700">
                    Edit
                </a>
                <a href="{{ route('banner.index') }}"
                   class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                    Back
                </a>
            </div>
        </div>
    </div>
@endsection
