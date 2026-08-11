@extends('layouts.app')

@section('title', $material->title ?: 'Assignment')

@section('content')
    @php
        $due = $material->due_date;
        $isPastDue = $material->isPastDue();
        $maxFiles = $material->max_files ?? 5;
        $files = $submission?->files ?? collect();
        $slotsLeft = max(0, $maxFiles - $files->count());
    @endphp

    <div class="mx-auto max-w-6xl space-y-4">
        {{-- Breadcrumb back to the course --}}
        <div>
            <a href="{{ route('courses.show', $material->section->course) }}"
               class="text-base text-slate-900">
                &larr; Back To Course
            </a>
        </div>

        {{-- Header --}}
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-xl font-semibold text-slate-900">
                        {{ $material->title ?: 'Assignment' }}
                    </h1>
                    @if ($due)
                        <p class="mt-1 text-sm text-slate-900">
                            Due: <span class="font-mono">{{ $due->format('Y-m-d H:i') }}</span>
                        </p>
                    @else
                        <p class="mt-1 text-sm italic text-slate-900">No due date.</p>
                    @endif
                </div>

                @if ($due)
                    <div class="flex-shrink-0">
                        @if ($isPastDue)
                            <span class="inline-flex items-center rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-700">
                                Submissions closed
                            </span>
                        @else
                            <span x-data="{
                                    target: {{ $due->getTimestamp() * 1000 }},
                                    remaining: '',
                                    tick() {
                                        const diff = this.target - Date.now();
                                        if (diff <= 0) { this.remaining = 'Past due'; return; }
                                        const d = Math.floor(diff / 86400000);
                                        const h = Math.floor((diff % 86400000) / 3600000);
                                        const m = Math.floor((diff % 3600000) / 60000);
                                        this.remaining = (d > 0 ? d + 'd ' : '') + h + 'h ' + m + 'm';
                                    },
                                }"
                                x-init="tick(); setInterval(() => tick(), 30000)"
                                class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700">
                                <span x-text="'In ' + remaining"></span>
                            </span>
                        @endif
                    </div>
                @endif
            </div>

        </div>

        {{-- Description --}}
        @if ($material->body)
            <div class="prose-section rounded-lg border border-slate-200 bg-white p-4 text-sm text-black">
                {!! $material->body !!}
            </div>
        @endif

        {{-- Grade + comment card (shown once teacher grades) --}}
        @if ($submission?->isGraded())
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                <div class="flex items-baseline gap-2">
                    <h2 class="text-sm font-semibold text-emerald-900">Grade:</h2>
                    <span class="text-lg font-bold text-emerald-900">{{ $submission->grade }}</span>
                </div>
                @if ($submission->comment)
                    <p class="mt-2 whitespace-pre-wrap text-sm text-emerald-900">{{ $submission->comment }}</p>
                @endif
                <p class="mt-2 text-xs text-emerald-700">
                    Graded on {{ $submission->graded_at->format('Y-m-d H:i') }}
                </p>
            </div>
        @endif

        {{-- Upload UI (hidden if past due) --}}
        @if (! $isPastDue)
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <h2 class="mb-2 text-base font-semibold text-slate-900">Upload Files</h2>

                @if ($errors->any())
                    <div class="mb-3 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
                        <ul class="list-inside list-disc space-y-1">
                            @foreach ($errors->all() as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($slotsLeft > 0)
                    <form method="POST" action="{{ route('submissions.upload', $material) }}"
                          enctype="multipart/form-data"
                          x-data="{ chosen: [] }"
                          class="space-y-3">
                        @csrf
                        <input type="file" name="files[]" multiple
                               accept="application/pdf,image/jpeg,image/png,image/webp"
                               @change="chosen = Array.from($event.target.files)"
                               class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white" />

                        <template x-if="chosen.length > 0">
                            <ul class="space-y-1 text-xs text-slate-600">
                                <template x-for="f in chosen" :key="f.name + f.size">
                                    <li>
                                        <span class="font-mono" x-text="f.name"></span>
                                        (<span x-text="Math.round(f.size / 1024) + ' KB'"></span>)
                                    </li>
                                </template>
                            </ul>
                        </template>

                        <button type="submit"
                                :disabled="chosen.length === 0"
                                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                            Upload
                        </button>
                    </form>
                @else
                    <p class="rounded-md bg-slate-100 p-3 text-sm text-slate-600">
                        You've reached the max of {{ $maxFiles }} files. Remove a file below to upload another.
                    </p>
                @endif
            </div>
        @endif

        {{-- Submitted files --}}
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-2 text-base font-semibold text-slate-900">
                My Files ({{ $files->count() }})
            </h2>

            @if ($files->isEmpty())
                <p class="text-sm italic text-slate-700">
                    @if ($isPastDue)
                        You did not submit any files before the deadline.
                    @else
                        No files uploaded yet.
                    @endif
                </p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($files as $file)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <div class="min-w-0">
                                <a href="{{ route('submission-files.download', $file) }}"
                                   class="truncate text-sm text-sky-700 hover:underline">
                                    {{ $file->original_name }}
                                </a>
                                <p class="text-sm text-slate-900">
                                    {{ $file->uploaded_at->format('Y-m-d H:i') }}
                                </p>
                            </div>
                            @if (! $isPastDue)
                                <form method="POST" action="{{ route('submission-files.destroy', $file) }}"
                                      onsubmit="return confirm('Remove {{ addslashes($file->original_name) }}?');">
                                    @csrf @method('DELETE')
                                    <button type="submit"
                                            class="text-sm text-red-600 hover:underline">
                                        Remove
                                    </button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection
