@extends('layouts.app')

@section('title', $course->name)

@section('content')
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $course->name }}</h1>
            @if (auth()->user()?->canAny(['courses.manage_teachers', 'courses.manage_students', 'sections.manage']))
                <div class="mt-3">
                    <a href="{{ route('courses.edit', $course) }}"
                       class="inline-flex items-center rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800">
                        Manage course
                    </a>
                </div>
            @endif
        </div>

        @php
            $visibleSections = ($canManage ?? false)
                ? $course->sections
                : $course->sections->filter(fn ($s) => $s->isVisibleToStudents());

            // Which sections auto-expand on load:
            //   1. Every section WITHOUT a scheduled_at — standalone content,
            //      shown open by default.
            //   2. Every section WITH a scheduled_at that falls within the
            //      CURRENT calendar week (Monday 00:00 → Sunday 23:59:59).
            //      Teachers often add multiple sections per week (Reading,
            //      Exercises, etc.) — this expands them all together.
            //   Older dated sections stay folded; future ones are already
            //   hidden by isVisibleToStudents().
            $weekStart = now()->startOfWeek();
            $weekEnd = now()->endOfWeek();
            $initialOpenIds = $visibleSections
                ->filter(fn ($s) =>
                    $s->scheduled_at === null
                    || $s->scheduled_at->between($weekStart, $weekEnd)
                )
                ->pluck('id')
                ->all();
        @endphp

        @if ($visibleSections->isEmpty())
            <p class="rounded-md border border-slate-200 bg-white p-6 text-sm text-slate-500">
                No content has been published yet for this course.
            </p>
        @else
            <div class="space-y-4"
                 x-data="{
                     openSections: { {{ collect($initialOpenIds)->map(fn ($id) => "$id: true")->implode(', ') }} },
                     toggle(id) { this.openSections[id] = ! this.openSections[id] },
                     expandAll() { {{ $visibleSections->pluck('id')->map(fn ($id) => "this.openSections[$id] = true;")->implode(' ') }} },
                     collapseAll() { {{ $visibleSections->pluck('id')->map(fn ($id) => "this.openSections[$id] = false;")->implode(' ') }} },
                 }">

                {{-- Global expand/collapse control --}}
                <div class="flex justify-end gap-2 text-xs">
                    <button type="button" @click="expandAll()"
                            class="rounded-md border border-slate-300 bg-white px-2.5 py-1 font-medium text-slate-700 hover:bg-slate-50">
                        Expand all
                    </button>
                    <button type="button" @click="collapseAll()"
                            class="rounded-md border border-slate-300 bg-white px-2.5 py-1 font-medium text-slate-700 hover:bg-slate-50">
                        Collapse all
                    </button>
                </div>

                @foreach ($visibleSections as $section)
                    <article class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                        <header @click="toggle({{ $section->id }})"
                                class="flex cursor-pointer items-center gap-2 border-b border-slate-100 bg-slate-50 px-4 py-3 hover:bg-slate-100"
                                :class="openSections[{{ $section->id }}] ? '' : 'border-b-0'">
                            {{-- Chevron on the left, tree-view style: points down
                                 when open, right when closed. Reads left-to-right
                                 as "toggle → title". --}}
                            <svg class="h-4 w-4 flex-shrink-0 transition-transform"
                                 :class="openSections[{{ $section->id }}] ? 'rotate-0' : '-rotate-90'"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                            <h2 class="text-base font-medium text-slate-900">
                                {{ $section->title }}
                                @unless ($section->is_published)
                                    <span class="ml-1 rounded bg-amber-100 px-1.5 text-xs text-amber-800">draft</span>
                                @endunless
                            </h2>
                        </header>

                        <div x-show="openSections[{{ $section->id }}]" x-cloak>
                            @php
                                $visibleMaterials = ($canManage ?? false)
                                    ? $section->materials
                                    : $section->materials->where('is_published', true);
                            @endphp

                            @if ($visibleMaterials->isEmpty())
                                <p class="px-4 py-4 text-xs italic text-slate-400">Nothing here yet.</p>
                            @else
                                <div class="divide-y divide-slate-100">
                                    @foreach ($visibleMaterials as $material)
                                        <x-material-item :material="$material" />
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
@endsection
