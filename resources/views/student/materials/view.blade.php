@extends('layouts.app')

@section('title', $material->title)

@section('content')
    @php
        $section = $material->section;
        $course = $section?->course;
    @endphp

    <div class="space-y-4">
        {{-- Header bar --}}
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-base font-semibold text-slate-900">
                    {{ $material->title }}
                </h1>
            </div>

            <div class="flex flex-shrink-0 gap-2">
                <a href="{{ route('materials.download', $material) }}"
                   class="inline-flex items-center gap-1.5 rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                    </svg>
                    Download
                </a>
            </div>
        </div>

        {{-- PDF viewer --}}
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-100 shadow-sm">
            <iframe src="{{ $pdfUrl }}"
                    title="{{ $material->title }}"
                    class="block h-[calc(100vh-12rem)] min-h-[480px] w-full border-0 bg-white"
                    loading="lazy"></iframe>
        </div>

        {{-- Fallback note for browsers without inline PDF support (mobile Safari etc.) --}}
        <p class="text-xs text-slate-500">
            Trouble viewing?
            <a href="{{ $pdfUrl }}" target="_blank" rel="noopener" class="underline hover:text-slate-700">
                Open in a new tab
            </a>
            or use the Download button.
        </p>
    </div>
@endsection
