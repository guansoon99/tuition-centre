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
        @endphp

        @if ($visibleSections->isEmpty())
            <p class="rounded-md border border-slate-200 bg-white p-6 text-sm text-slate-500">
                No content has been published yet for this course.
            </p>
        @else
            <div class="space-y-4">
                @foreach ($visibleSections as $section)
                    <article class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                        <header class="border-b border-slate-100 bg-slate-50 px-4 py-3">
                            <h2 class="text-base font-medium text-slate-900">
                                {{ $section->title }}
                                @unless ($section->is_published)
                                    <span class="ml-1 rounded bg-amber-100 px-1.5 text-xs text-amber-800">draft</span>
                                @endunless
                            </h2>
                        </header>

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
                    </article>
                @endforeach
            </div>
        @endif
    </div>
@endsection
