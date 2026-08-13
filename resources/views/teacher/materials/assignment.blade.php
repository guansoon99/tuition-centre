@extends('layouts.app')

@section('title', ($material->title ?: 'Assignment').' — submissions')

@section('content')
    @php
        $due = $material->due_date;
        $isPastDue = $material->isPastDue();

        $submittedCount = $students->filter(function ($s) use ($submissions) {
            $sub = $submissions->get($s->id);
            return $sub && $sub->files->isNotEmpty();
        })->count();
        $totalCount = $students->count();
        $ungradedCount = $submissions->filter(fn ($sub) => $sub->files->isNotEmpty() && ! $sub->isGraded())->count();
    @endphp

    <div class="mx-auto max-w-6xl space-y-4">
        {{-- Breadcrumb --}}
        <div>
            <a href="{{ route('courses.edit', [$material->section->course, 'tab' => 'materials']) }}"
               class="text-base text-slate-900">
                &larr; Back To Course
            </a>
        </div>

        {{-- Header card --}}
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-xl font-semibold text-slate-900">
                        {{ $material->title ?: 'Assignment' }}
                    </h1>
                    @if ($due)
                        <p class="mt-1 text-sm text-slate-900">
                            Due: <span class="font-mono">{{ $due->format('Y-m-d H:i') }}</span>
                            @if ($isPastDue)
                                <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                    Closed
                                </span>
                            @endif
                        </p>
                    @else
                        <p class="mt-1 text-sm italic text-slate-900">No due date.</p>
                    @endif
                    <p class="mt-1 text-sm text-slate-900">
                        Max {{ $material->max_file_size_mb ?? \App\Models\Material::DEFAULT_MAX_FILE_SIZE_MB }}MB per file &middot;
                        {{ $material->maxFiles() }} files max per student
                    </p>
                </div>

                <div class="flex flex-shrink-0 gap-2 text-center">
                    <div class="rounded-md bg-slate-100 px-3 py-2">
                        <p class="text-xl font-bold text-slate-900">{{ $submittedCount }}<span class="text-sm font-normal text-slate-700">/{{ $totalCount }}</span></p>
                        <p class="text-xs text-slate-600">Submitted</p>
                    </div>
                    <div class="rounded-md bg-amber-100 px-3 py-2">
                        <p class="text-xl font-bold text-amber-900">{{ $ungradedCount }}</p>
                        <p class="text-xs text-amber-800">To Grade</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Roster --}}
        @if ($students->isEmpty())
            <div class="rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-700">
                No enrolled students in this course yet.
            </div>
        @else
            <div x-data="{ status: 'all', search: '' }" class="space-y-3">
                {{-- Filter bar (client-side, no reload) --}}
                <div class="flex flex-wrap items-center gap-3 rounded-md border border-slate-200 bg-white p-3">
                    <select x-model="status"
                            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value="all">All</option>
                        <option value="submitted">Submitted</option>
                        <option value="not-submitted">Not Submitted</option>
                        <option value="to-grade">To Grade</option>
                    </select>
                    <input type="text" x-model="search" placeholder="Search name or username…"
                           class="min-w-[12rem] flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    <button type="button"
                            @click="status = 'all'; search = ''"
                            class="rounded-md bg-red-500 px-3 py-1.5 text-sm text-white hover:bg-red-600">
                        Clear
                    </button>
                    @if ($submittedCount > 0)
                        <a href="{{ route('submissions.download-all', $material) }}"
                           class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                            </svg>
                            Download
                        </a>
                    @endif
                </div>

                <div class="space-y-2">
                @foreach ($students as $student)
                    @php
                        $submission = $submissions->get($student->id);
                        $files = $submission?->files ?? collect();
                        $hasSubmitted = $submission && $files->isNotEmpty();
                        $isMissing = ! $hasSubmitted && $isPastDue;
                        $isToGrade = $hasSubmitted && ! $submission->isGraded();
                        $rowStatus = $hasSubmitted ? 'submitted' : 'not-submitted';
                        $searchable = strtolower($student->name.' '.$student->username);
                    @endphp

                    <div x-show="(status === 'all'
                                  || status === '{{ $rowStatus }}'
                                  || (status === 'to-grade' && {{ $isToGrade ? 'true' : 'false' }}))
                              && (search === '' || @js($searchable).includes(search.toLowerCase()))"
                         @class([
                        'rounded-lg border bg-white p-4',
                        'border-red-200 bg-red-50' => $isMissing,
                        'border-slate-200' => ! $isMissing,
                    ])>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">
                                    {{ $student->name }}
                                    <span class="font-normal text-slate-900">({{ $student->username }})</span>
                                </p>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                    @if ($hasSubmitted)
                                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-700">
                                            Submitted
                                        </span>
                                        <span class="text-slate-900">
                                            {{ $submission->submitted_at?->format('Y-m-d H:i') }}
                                        </span>
                                    @elseif ($isMissing)
                                        <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 font-medium text-red-700">
                                            Missing
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-600">
                                            Not submitted
                                        </span>
                                    @endif

                                    @if ($submission?->isGraded())
                                        <span class="inline-flex items-center rounded-full bg-sky-100 px-2 py-0.5 font-medium text-sky-800">
                                            Graded: {{ $submission->grade ?: '—' }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Files --}}
                        @if ($files->isNotEmpty())
                            <div class="mt-3">
                                <p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-700">
                                    Files ({{ $files->count() }})
                                </p>
                                <ul class="space-y-1">
                                    @foreach ($files as $file)
                                        <li class="flex items-center justify-between gap-3 rounded-md bg-slate-50 px-3 py-2 text-sm">
                                            <div class="min-w-0">
                                                <a href="{{ route('submission-files.download', $file) }}"
                                                   class="truncate text-sky-700 hover:underline">
                                                    {{ $file->original_name }}
                                                </a>
                                                <p class="text-sm text-slate-900">
                                                    {{ $file->uploaded_at->format('Y-m-d H:i') }}
                                                </p>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{-- Grade form — hidden if no files and no existing grade (avoids
                             offering the form for stale empty-submission rows). --}}
                        @if ($submission && ($files->isNotEmpty() || $submission->isGraded()))
                            <form method="POST" action="{{ route('submissions.grade', $submission) }}"
                                  class="mt-3 grid grid-cols-1 gap-2 border-t border-slate-100 pt-3 sm:grid-cols-[8rem,1fr,auto]">
                                @csrf @method('PATCH')
                                <input type="text" name="grade" maxlength="32"
                                       value="{{ $submission->grade }}"
                                       placeholder="Grade (e.g. 85)"
                                       class="rounded-md border border-slate-300 px-3 py-2 text-sm" />
                                <input type="text" name="comment" maxlength="2000"
                                       value="{{ $submission->comment }}"
                                       placeholder="Comment (optional)"
                                       class="rounded-md border border-slate-300 px-3 py-2 text-sm" />
                                <button type="submit"
                                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                                    Save
                                </button>
                            </form>
                        @endif
                    </div>
                @endforeach
                </div>
            </div>
        @endif
    </div>
@endsection
