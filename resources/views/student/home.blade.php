@extends('layouts.app')

@section('title', 'Home')

@section('content')
    <div class="space-y-8">
        @if ($imageAnnouncements->isNotEmpty() || $textAnnouncements->isNotEmpty())
        <div class="space-y-0">
        {{-- Image announcements → banner-style slideshow at the top of the home page. --}}
        @if ($imageAnnouncements->isNotEmpty())
            <section class="relative bg-slate-900"
                     x-data="{
                        current: 0,
                        total: {{ $imageAnnouncements->count() }},
                        paused: false,
                        next() { this.current = (this.current + 1) % this.total },
                        prev() { this.current = (this.current - 1 + this.total) % this.total },
                     }"
                     x-init="setInterval(() => { if (! paused && total > 1) next() }, 3000)"
                     @mouseenter="paused = true"
                     @mouseleave="paused = false">
                <div class="relative mx-auto w-full overflow-hidden">
                    {{-- Invisible sizer: first image sets the container height. --}}
                    <img src="{{ $imageAnnouncements->first()->image_url }}"
                         alt="" aria-hidden="true"
                         class="invisible block h-auto w-full" />

                    @foreach ($imageAnnouncements as $i => $a)
                        <img src="{{ $a->image_url }}"
                             alt="{{ $a->title }}"
                             x-show="current === {{ $i }}"
                             x-transition:enter="transition-opacity duration-700"
                             x-transition:enter-start="opacity-0"
                             x-transition:enter-end="opacity-100"
                             x-transition:leave="transition-opacity duration-700"
                             x-transition:leave-start="opacity-100"
                             x-transition:leave-end="opacity-0"
                             @if ($i > 0) style="display:none;" @endif
                             class="absolute inset-0 h-full w-full object-contain" />
                    @endforeach

                    @if ($imageAnnouncements->count() > 1)
                        <button @click="prev()"
                                class="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-black/40 p-2 text-white hover:bg-black/60 sm:left-6"
                                aria-label="Previous announcement">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <button @click="next()"
                                class="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-black/40 p-2 text-white hover:bg-black/60 sm:right-6"
                                aria-label="Next announcement">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    @endif
                </div>
            </section>
        @endif

        {{-- Text announcements → horizontal scrolling marquee ribbon. --}}
        @if ($textAnnouncements->isNotEmpty())
            <section class="overflow-hidden bg-amber-50 border-y border-amber-200 py-2">
                <div class="marquee-track whitespace-nowrap text-sm text-amber-900">
                    @foreach ($textAnnouncements as $a)
                        <span class="mx-8 inline-block">
                            <span class="mr-2">📢</span>
                            <strong>{{ $a->title }}</strong>@if (trim($a->body) !== ''): {{ $a->body }}@endif
                        </span>
                    @endforeach
                </div>
            </section>
            @push('head')
                <style>
                    .marquee-track {
                        display: inline-block;
                        padding-left: 100%;
                        animation: marquee-scroll 40s linear infinite;
                    }
                    .marquee-track:hover { animation-play-state: paused; }
                    @keyframes marquee-scroll {
                        0%   { transform: translateX(0); }
                        100% { transform: translateX(-100%); }
                    }
                </style>
            @endpush
        @endif
        </div>
        @endif

        @if ($recentCourses->isNotEmpty() && ! $user->hasRole('student'))
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
