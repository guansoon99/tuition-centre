@extends('layouts.app')

@section('title', 'Home')

@section('content')
    <div class="space-y-8">
        @if ($announcements->isNotEmpty())
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-600">
                    Announcements
                </h2>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($announcements as $a)
                        <article class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="flex items-baseline justify-between gap-3">
                                <h3 class="text-sm font-semibold text-slate-900">
                                    {{ $a->title }}
                                </h3>
                                <span class="shrink-0 text-xs text-slate-600">
                                    {{ $a->created_at->format('Y-m-d H:i') }}
                                </span>
                            </div>

                            @if (trim($a->body) !== '')
                                <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $a->body }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($recentCourses->isNotEmpty())
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-600">Recently accessed</h2>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($recentCourses as $course)
                        <x-course-card :course="$course" />
                    @endforeach
                </div>
            </section>
        @endif

        <section>
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-600">All courses</h2>
            @if ($allCourses->isEmpty())
                <p class="rounded-md border border-slate-200 bg-white p-6 text-sm text-slate-600">
                    You're not enrolled in any courses yet. Please contact your tuition centre administrator.
                </p>
            @else
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($allCourses as $course)
                        <x-course-card :course="$course" />
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection
