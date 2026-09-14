@extends('layouts.public')

@section('title', \App\Models\SiteSettings::current()->displayName())

@php
    $settings = \App\Models\SiteSettings::current();
    $siteName = $settings->displayName();
    $editing = $editing ?? false;

    // The words on this page come from HomepageContent: fixed shapes with
    // defaults, overridden by what the admin saves from edit mode. The hero
    // is the admin's own uploaded posters (banner slides) and has no words
    // of its own.
    $content = $content ?? \App\Support\HomepageContent::all();
    $fill = fn (?string $t) => \App\Support\HomepageContent::fill($t);
    $features = $content['features']['items'];
    $reviews = $content['reviews'];
    $cta = $content['cta'];
    $initials = fn (string $name) => mb_strtoupper(mb_substr($name, 0, 1));

    // Edit mode only: the button each block wears.
    $editButton = 'absolute right-4 top-4 z-10 inline-flex items-center gap-1.5 rounded-full bg-slate-900/90 px-3 py-1.5 text-xs font-semibold text-white shadow-lg hover:bg-orange-600';
    $pencil = '<svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z"/></svg>';
@endphp

@section('content')
    {{-- ============================================================ Hero --}}
    {{-- The hero IS the uploaded banners: posters designed by the admin,
         shown as they are, edge to edge inside the page width. No text is
         laid over them -- whatever the poster says is the message. --}}
    <section id="top" class="bg-amber-50">
        <div class="relative mx-auto max-w-6xl px-4 py-6 sm:py-8">
            @if ($editing)
                @can('banner.view')
                    <a href="{{ route('banner.index') }}" class="{{ $editButton }} right-8 top-10" aria-label="Manage posters">{!! $pencil !!} Manage posters</a>
                @endcan
            @endif
            @if ($slides->isNotEmpty())
                <div class="relative overflow-hidden rounded-3xl bg-white shadow-2xl shadow-orange-100 ring-1 ring-orange-100"
                     x-data="{
                        current: 0,
                        total: {{ $slides->count() }},
                        paused: false,
                        next() { this.current = (this.current + 1) % this.total },
                        prev() { this.current = (this.current - 1 + this.total) % this.total },
                     }"
                     x-init="setInterval(() => { if (! paused && total > 1) next() }, 4000)"
                     @mouseenter="paused = true"
                     @mouseleave="paused = false">
                    {{-- The first slide sizes the frame at its natural aspect
                         ratio, capped at 70% of the viewport so a tall poster
                         cannot push everything else off screen; the rest are
                         overlaid so the cross-fade never changes the height.
                         Posters should share one size (the banner form
                         recommends 1600x500). --}}
                    <img src="{{ $slides->first()->image_url }}" alt="" aria-hidden="true" class="invisible block h-auto max-h-[70vh] w-full" />
                    @foreach ($slides as $i => $slide)
                        <img src="{{ $slide->image_url }}"
                             alt="{{ $slide->title }}"
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

                    @if ($slides->count() > 1)
                        <button @click="prev()" aria-label="Previous slide"
                                class="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-black/40 p-2 text-white hover:bg-black/60 sm:left-5">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <button @click="next()" aria-label="Next slide"
                                class="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-black/40 p-2 text-white hover:bg-black/60 sm:right-5">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                        <div class="absolute bottom-3 left-1/2 flex -translate-x-1/2 gap-1.5">
                            @foreach ($slides as $i => $slide)
                                <button @click="current = {{ $i }}" aria-label="Slide {{ $i + 1 }}"
                                        class="h-2 w-2 rounded-full transition"
                                        :class="current === {{ $i }} ? 'bg-orange-500' : 'bg-white/70'"></button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @else
                {{-- No poster uploaded yet: a warm placeholder at the same
                     spot, so the page still opens on something. --}}
                <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-orange-500 via-amber-400 to-amber-300 px-8 py-16 text-white shadow-2xl shadow-orange-100 sm:px-14 sm:py-24">
                    <div class="absolute -right-10 -top-10 h-56 w-56 rounded-full bg-white/20 blur-2xl" aria-hidden="true"></div>
                    <div class="absolute -bottom-16 -left-16 h-72 w-72 rounded-full bg-orange-700/30 blur-2xl" aria-hidden="true"></div>
                    <div class="relative max-w-2xl">
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-orange-100">{{ $siteName }}</p>
                        <p class="mt-4 text-4xl font-bold sm:text-5xl">Selamat datang.</p>
                        <p class="mt-4 text-base text-orange-50 sm:text-lg">Belajar dengan teratur, dapat keputusan yang lebih baik.</p>
                        <a href="{{ route('login') }}"
                           class="mt-8 inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 text-sm font-semibold text-orange-600 shadow-lg transition hover:bg-orange-50">
                            Login to Oster
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </section>

    {{-- ============================================================ Features --}}
    <section id="about" class="scroll-mt-20 bg-white">
        <div class="relative mx-auto max-w-6xl px-4 py-14">
        @if ($editing)
            <button type="button" @click="$dispatch('homepage-edit', 'features')" class="{{ $editButton }} top-4" aria-label="Edit feature cards">{!! $pencil !!} Edit</button>
        @endif
        {{-- A slider, not a grid: cards sit in one row that scrolls sideways
             (swipe, or the arrows at either end), so more can be added than
             fit on screen. Four fill a desktop row exactly; the arrows grey
             out when there is nothing further that way. --}}
        <div x-data="homeSlider()" class="relative">
        <div x-ref="track" data-slider="features" @scroll.passive="update()"
             class="flex snap-x snap-mandatory gap-5 overflow-x-auto scroll-smooth pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach ($features as $feature)
                <div class="flex w-[85%] shrink-0 snap-start gap-4 rounded-2xl border border-orange-100 bg-white p-5 shadow-sm shadow-orange-50 sm:w-[calc(50%-0.625rem)] lg:w-[calc(25%-0.9375rem)]">
                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-amber-100 text-orange-600" aria-hidden="true">
                        @if (! empty($feature['image']))
                            <img src="{{ \App\Support\PublicFile::url($feature['image']) }}" alt="" class="h-full w-full object-cover" data-card-image />
                        @else
                        @switch($feature['icon'])
                            @case('book')
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                                @break
                            @case('notes')
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                                @break
                            @case('play')
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15.91 11.672a.375.375 0 010 .656l-5.603 3.113a.375.375 0 01-.557-.328V8.887c0-.286.307-.466.557-.327l5.603 3.112z"/></svg>
                                @break
                            @case('cap')
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5"/></svg>
                                @break
                            @case('star')
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor"><path d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.007 5.404.433c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.433 2.082-5.006z"/></svg>
                                @break
                            @default
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>
                        @endswitch
                        @endif
                    </span>
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ $fill($feature['title']) }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ $fill($feature['text']) }}</p>
                    </div>
                </div>
            @endforeach
        </div>
        <button :disabled="! canPrev" @click="prev()" aria-label="Previous features"
                class="absolute -left-2 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white text-slate-700 shadow-lg ring-1 ring-orange-100 transition hover:text-orange-600 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:text-slate-700 sm:-left-5">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <button :disabled="! canNext" @click="next()" aria-label="More features"
                class="absolute -right-2 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white text-slate-700 shadow-lg ring-1 ring-orange-100 transition hover:text-orange-600 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:text-slate-700 sm:-right-5">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </button>
        </div>
        </div>
    </section>

    {{-- ============================================================ Reviews --}}
    @if ($editing || count($reviews['items']) > 0)
    <section id="reviews" class="scroll-mt-20 bg-amber-50/60">
        <div class="relative mx-auto max-w-6xl px-4 py-14">
            @if ($editing)
                <button type="button" @click="$dispatch('homepage-edit', 'reviews')" class="{{ $editButton }} top-4" aria-label="Edit student reviews">{!! $pencil !!} Edit</button>
            @endif
            <div class="text-center">
                @if ($reviews['eyebrow'])
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-orange-600">{{ $fill($reviews['eyebrow']) }}</p>
                @endif
                <h2 class="mt-2 text-2xl font-bold text-slate-900 sm:text-3xl">{{ $fill($reviews['heading']) }}</h2>
                @if ($reviews['subheading'])
                    <p class="mt-2 text-sm text-slate-600">{{ $fill($reviews['subheading']) }}</p>
                @endif
            </div>
            @if (count($reviews['items']) === 0)
                <p class="mt-8 rounded-2xl border border-dashed border-orange-200 p-6 text-center text-sm text-slate-600">No reviews yet. Click Edit to add the first one.</p>
            @endif
            {{-- Same slider as the features: three reviews fill a desktop
                 row, the rest are a swipe or an arrow away. --}}
            <div x-data="homeSlider()" class="relative mt-10">
            <div x-ref="track" data-slider="reviews" @scroll.passive="update()"
                 class="flex snap-x snap-mandatory gap-5 overflow-x-auto scroll-smooth pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @foreach ($reviews['items'] as $review)
                    <figure class="relative w-[85%] shrink-0 snap-start rounded-2xl border border-orange-100 bg-white p-6 shadow-sm shadow-orange-50 sm:w-[calc(50%-0.625rem)] md:w-[calc(33.333%-0.834rem)]">
                        <span class="absolute right-5 top-4 text-5xl leading-none text-orange-200" aria-hidden="true">&rdquo;</span>
                        <figcaption class="flex items-center gap-3">
                            @if (! empty($review['image']))
                                <img src="{{ \App\Support\PublicFile::url($review['image']) }}" alt="" class="h-11 w-11 shrink-0 rounded-full object-cover" data-review-photo />
                            @else
                                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-amber-100 text-base font-semibold text-orange-700" aria-hidden="true">{{ $initials($review['name']) }}</span>
                            @endif
                            <div>
                                <p class="font-semibold text-slate-900">{{ $review['name'] }}</p>
                                <p class="flex gap-0.5 text-orange-500" aria-label="{{ $review['stars'] }} out of 5 stars">
                                    @for ($s = 0; $s < (int) $review['stars']; $s++)
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.007 5.404.433c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.433 2.082-5.006z"/></svg>
                                    @endfor
                                </p>
                            </div>
                        </figcaption>
                        <blockquote class="mt-4 text-sm leading-relaxed text-slate-700">{{ $review['quote'] }}</blockquote>
                    </figure>
                @endforeach
            </div>
            <button :disabled="! canPrev" @click="prev()" aria-label="Previous reviews"
                    class="absolute -left-2 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white text-slate-700 shadow-lg ring-1 ring-orange-100 transition hover:text-orange-600 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:text-slate-700 sm:-left-5">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <button :disabled="! canNext" @click="next()" aria-label="More reviews"
                    class="absolute -right-2 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white text-slate-700 shadow-lg ring-1 ring-orange-100 transition hover:text-orange-600 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:text-slate-700 sm:-right-5">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>
            </div>
        </div>
    </section>
    @endif

    {{-- ============================================================ Already a student --}}
    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-12">
            <div class="relative flex flex-col items-start gap-6 rounded-3xl bg-amber-100 px-6 py-8 sm:flex-row sm:items-center sm:justify-between sm:px-10">
                @if ($editing)
                    <button type="button" @click="$dispatch('homepage-edit', 'cta')" class="{{ $editButton }}" aria-label="Edit the already-a-student strip">{!! $pencil !!} Edit</button>
                @endif
                <div class="flex items-center gap-4">
                    <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-orange-500 text-white shadow-md shadow-orange-200" aria-hidden="true">
                        <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5"/></svg>
                    </span>
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">{{ $fill($cta['heading']) }}</h2>
                        @if ($cta['text'])
                            <p class="mt-1 text-sm text-slate-700">{{ $fill($cta['text']) }}</p>
                        @endif
                    </div>
                </div>
                <a href="{{ route('login') }}"
                   class="inline-flex items-center gap-2 rounded-full bg-orange-500 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-orange-200 transition hover:bg-orange-600">
                    {{ $fill($cta['button']) }}
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                </a>
            </div>
        </div>
    </section>

    @if ($editing)
        {{-- ======================================================== The editor --}}
        {{-- One panel for every block. An "Edit" button anywhere on the page
             dispatches the block's name; the panel opens with a copy of that
             block's fields, saves them as JSON, and the page reloads so what
             is shown is exactly what was stored. --}}
        @php
            $editorUrls = [];
            foreach (array_keys(\App\Support\HomepageContent::blocks()) as $key) {
                $editorUrls[$key] = route('homepage.update', $key);
            }
        @endphp
        <div x-data="homepageEditor(@js($editorContent ?? $content), @js($editorUrls), @js(\App\Support\HomepageContent::labels()), @js(route('homepage.upload-image')))"
             @homepage-edit.window="open($event.detail)"
             @keydown.escape.window="close()"
             data-homepage-editor>
            {{-- The bar that says this is edit mode --}}
            <div class="fixed inset-x-0 bottom-0 z-40 border-t border-orange-200 bg-white/95 backdrop-blur">
                <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-2.5 text-sm">
                    <p class="text-slate-700"><span class="font-semibold text-orange-600">Editing the homepage.</span> Click <span class="font-semibold">Edit</span> on a block. Changes go live when you save.</p>
                    <a href="{{ route('home') }}" class="rounded-full bg-slate-900 px-4 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">Back to admin</a>
                </div>
            </div>

            {{-- The panel --}}
            <div x-cloak x-show="block !== null" class="fixed inset-0 z-50 flex" role="dialog" aria-modal="true" :aria-label="block ? labels[block] : ''">
                <div class="flex-1 bg-slate-900/40" @click="close()"></div>
                <form @submit.prevent="save()" class="flex w-full max-w-lg flex-col bg-white shadow-2xl">
                    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                        <h2 class="text-base font-semibold text-slate-900" x-text="block ? labels[block] : ''"></h2>
                        <button type="button" @click="close()" class="rounded-md p-1 text-slate-600 hover:bg-slate-100 hover:text-slate-900" aria-label="Close">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5 text-sm">
                        <p class="text-xs text-slate-600">Tip: <code>{name}</code> becomes the site name and <code>{year}</code> the current year.</p>

                        {{-- Feature cards --}}
                        <template x-if="block === 'features'">
                            <div class="space-y-4" data-editor-form="features">
                                <template x-for="(item, i) in draft.items" :key="i">
                                    <div class="space-y-2 rounded-xl border border-slate-200 p-3">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-600" x-text="'Card ' + (i + 1)"></span>
                                            <div class="flex gap-1">
                                                <button type="button" @click="move('items', i, -1)" :disabled="i === 0" class="rounded-md border border-slate-300 px-2 py-0.5 text-xs disabled:opacity-30" aria-label="Move up">↑</button>
                                                <button type="button" @click="move('items', i, 1)" :disabled="i === draft.items.length - 1" class="rounded-md border border-slate-300 px-2 py-0.5 text-xs disabled:opacity-30" aria-label="Move down">↓</button>
                                                <button type="button" @click="remove('items', i)" class="rounded-md border border-red-300 px-2 py-0.5 text-xs text-red-700" aria-label="Remove">✕</button>
                                            </div>
                                        </div>
                                        {{-- The picture on the card: a built-in icon, or an
                                             uploaded image of the admin's own. --}}
                                        <div class="flex items-center gap-3" data-card-picture>
                                            <template x-if="item.image">
                                                <img :src="item.image_url" alt="" class="h-11 w-11 shrink-0 rounded-xl object-cover ring-1 ring-slate-200" data-card-preview />
                                            </template>
                                            <label class="block flex-1" x-show="! item.image">
                                                <span class="text-xs text-slate-600">Icon</span>
                                                <select x-model="item.icon" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                                                    @foreach (\App\Support\HomepageContent::ICONS as $key => $label)
                                                        <option value="{{ $key }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <div class="flex flex-col gap-1">
                                                <label class="cursor-pointer rounded-md border border-slate-300 px-2.5 py-1 text-center text-xs font-semibold text-slate-700 hover:border-orange-400 hover:text-orange-600">
                                                    <span x-text="item.image ? 'Change image' : 'Upload image'"></span>
                                                    <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="uploadImage(item, $event)" data-card-upload />
                                                </label>
                                                <button type="button" x-show="item.image" @click="item.image = ''; item.image_url = ''"
                                                        class="rounded-md border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:border-orange-400 hover:text-orange-600">Use an icon instead</button>
                                            </div>
                                        </div>
                                        <label class="block">
                                            <span class="text-xs text-slate-600">Title</span>
                                            <input type="text" x-model="item.title" maxlength="60" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                                        </label>
                                        <label class="block">
                                            <span class="text-xs text-slate-600">Text</span>
                                            <textarea x-model="item.text" maxlength="200" rows="2" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"></textarea>
                                        </label>
                                    </div>
                                </template>
                                <button type="button" @click="add('items', { icon: 'star', image: '', image_url: '', title: '', text: '' })" class="rounded-md border border-dashed border-slate-400 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-orange-400 hover:text-orange-600">+ Add card</button>
                            </div>
                        </template>

                        {{-- Student reviews --}}
                        <template x-if="block === 'reviews'">
                            <div class="space-y-4" data-editor-form="reviews">
                                <label class="block">
                                    <span class="text-xs text-slate-600">Small line above the heading</span>
                                    <input type="text" x-model="draft.eyebrow" maxlength="60" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-slate-600">Heading</span>
                                    <input type="text" x-model="draft.heading" maxlength="80" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-slate-600">Line under the heading</span>
                                    <input type="text" x-model="draft.subheading" maxlength="160" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                                </label>
                                <template x-for="(item, i) in draft.items" :key="i">
                                    <div class="space-y-2 rounded-xl border border-slate-200 p-3">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-600" x-text="'Review ' + (i + 1)"></span>
                                            <div class="flex gap-1">
                                                <button type="button" @click="move('items', i, -1)" :disabled="i === 0" class="rounded-md border border-slate-300 px-2 py-0.5 text-xs disabled:opacity-30" aria-label="Move up">↑</button>
                                                <button type="button" @click="move('items', i, 1)" :disabled="i === draft.items.length - 1" class="rounded-md border border-slate-300 px-2 py-0.5 text-xs disabled:opacity-30" aria-label="Move down">↓</button>
                                                <button type="button" @click="remove('items', i)" class="rounded-md border border-red-300 px-2 py-0.5 text-xs text-red-700" aria-label="Remove">✕</button>
                                            </div>
                                        </div>
                                        {{-- The reviewer's picture: a photo of their own, or
                                             the first letter of the name. --}}
                                        <div class="flex items-center gap-3" data-review-picture>
                                            <template x-if="item.image">
                                                <img :src="item.image_url" alt="" class="h-11 w-11 shrink-0 rounded-full object-cover ring-1 ring-slate-200" data-review-preview />
                                            </template>
                                            <template x-if="! item.image">
                                                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-amber-100 text-base font-semibold text-orange-700" x-text="(item.name || '?').trim().charAt(0).toUpperCase() || '?'"></span>
                                            </template>
                                            <div class="flex flex-col gap-1">
                                                <label class="cursor-pointer rounded-md border border-slate-300 px-2.5 py-1 text-center text-xs font-semibold text-slate-700 hover:border-orange-400 hover:text-orange-600">
                                                    <span x-text="item.image ? 'Change photo' : 'Upload photo'"></span>
                                                    <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="uploadImage(item, $event)" data-review-upload />
                                                </label>
                                                <button type="button" x-show="item.image" @click="item.image = ''; item.image_url = ''"
                                                        class="rounded-md border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:border-orange-400 hover:text-orange-600">Use the initial instead</button>
                                            </div>
                                            <p class="text-xs text-slate-600">No photo: the first letter of the name is shown.</p>
                                        </div>
                                        <div class="grid grid-cols-[1fr,7rem] gap-2">
                                            <label class="block">
                                                <span class="text-xs text-slate-600">Name</span>
                                                <input type="text" x-model="item.name" maxlength="60" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                                            </label>
                                            <label class="block">
                                                <span class="text-xs text-slate-600">Stars</span>
                                                <select x-model.number="item.stars" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                                                    @foreach ([5, 4, 3, 2, 1] as $n)
                                                        <option value="{{ $n }}">{{ $n }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        </div>
                                        <label class="block">
                                            <span class="text-xs text-slate-600">Quote</span>
                                            <textarea x-model="item.quote" maxlength="600" rows="3" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"></textarea>
                                        </label>
                                    </div>
                                </template>
                                <button type="button" @click="add('items', { name: '', stars: 5, image: '', image_url: '', quote: '' })" class="rounded-md border border-dashed border-slate-400 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-orange-400 hover:text-orange-600">+ Add review</button>
                            </div>
                        </template>

                        {{-- Already a student strip --}}
                        <template x-if="block === 'cta'">
                            <div class="space-y-4" data-editor-form="cta">
                                <label class="block">
                                    <span class="text-xs text-slate-600">Heading</span>
                                    <input type="text" x-model="draft.heading" maxlength="80" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-slate-600">Text</span>
                                    <input type="text" x-model="draft.text" maxlength="200" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-slate-600">Button label</span>
                                    <input type="text" x-model="draft.button" maxlength="40" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                                </label>
                            </div>
                        </template>

                        {{-- Footer: the copyright line, the address and hours,
                             and the contact buttons, all in one place. --}}
                        <template x-if="block === 'footer'">
                            <div class="space-y-4" data-editor-form="footer">
                                <label class="block">
                                    <span class="text-xs text-slate-600">Copyright line</span>
                                    <input type="text" x-model="draft.copyright" maxlength="160" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                                </label>
                                <label class="block">
                                    <span class="text-xs text-slate-600">Address (optional)</span>
                                    <textarea x-model="draft.address" maxlength="500" rows="2" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"></textarea>
                                </label>
                                <label class="block">
                                    <span class="text-xs text-slate-600">Opening hours (optional)</span>
                                    <input type="text" x-model="draft.hours" maxlength="255" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                                </label>
                                <div class="space-y-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Contact buttons</p>
                                    <p class="text-xs text-slate-600">Shown in the footer and as the floating buttons on every page. Switch one off to hide it without deleting it.</p>
                                    <template x-for="(c, i) in draft.contacts" :key="i">
                                        <div class="space-y-2 rounded-xl border border-slate-200 p-3">
                                            <div class="flex items-center justify-between">
                                                <label class="inline-flex items-center gap-2 text-xs text-slate-700">
                                                    <input type="checkbox" x-model="c.active" class="rounded border-slate-300" /> Shown
                                                </label>
                                                <div class="flex gap-1">
                                                    <button type="button" @click="move('contacts', i, -1)" :disabled="i === 0" class="rounded-md border border-slate-300 px-2 py-0.5 text-xs disabled:opacity-30" aria-label="Move contact up">↑</button>
                                                    <button type="button" @click="move('contacts', i, 1)" :disabled="i === draft.contacts.length - 1" class="rounded-md border border-slate-300 px-2 py-0.5 text-xs disabled:opacity-30" aria-label="Move contact down">↓</button>
                                                    <button type="button" @click="remove('contacts', i)" class="rounded-md border border-red-300 px-2 py-0.5 text-xs text-red-700" aria-label="Remove contact">✕</button>
                                                </div>
                                            </div>
                                            {{-- The button's icon: the built-in one for its
                                                 type, or an uploaded icon of the admin's own. --}}
                                            <div class="flex items-center gap-3" data-contact-picture>
                                                <template x-if="c.icon">
                                                    <img :src="c.icon_url" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover ring-1 ring-slate-200" data-contact-preview />
                                                </template>
                                                <template x-if="! c.icon">
                                                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-900 text-[9px] font-extrabold text-white" x-text="({ phone: 'TEL', whatsapp: 'WA', telegram: 'TG', facebook: 'FB', xhs: 'XHS' })[c.type] || '?'"></span>
                                                </template>
                                                <div class="flex flex-col gap-1">
                                                    <label class="cursor-pointer rounded-md border border-slate-300 px-2.5 py-1 text-center text-xs font-semibold text-slate-700 hover:border-orange-400 hover:text-orange-600">
                                                        <span x-text="c.icon ? 'Change icon' : 'Upload icon'"></span>
                                                        <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="uploadImage(c, $event, 'icon')" data-contact-upload />
                                                    </label>
                                                    <button type="button" x-show="c.icon" @click="c.icon = ''; c.icon_url = ''"
                                                            class="rounded-md border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:border-orange-400 hover:text-orange-600">Use the default icon</button>
                                                </div>
                                                <p class="text-xs text-slate-600">Without an upload, the built-in icon for the type is used.</p>
                                            </div>
                                            <div class="grid grid-cols-[8rem,1fr] gap-2">
                                                <label class="block">
                                                    <span class="text-xs text-slate-600">Type</span>
                                                    <select x-model="c.type" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                                                        @foreach (\App\Models\Contact::TYPES as $type => $typeLabel)
                                                            <option value="{{ $type }}">{{ $typeLabel }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <label class="block">
                                                    <span class="text-xs text-slate-600">Number, username or link</span>
                                                    <input type="text" x-model="c.value" maxlength="100" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" placeholder="011 7240 3112, @username, or a page link" />
                                                </label>
                                            </div>
                                            <label class="block">
                                                <span class="text-xs text-slate-600">Label shown (optional)</span>
                                                <input type="text" x-model="c.label" maxlength="100" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" placeholder="Leave empty to show the number" />
                                            </label>
                                        </div>
                                    </template>
                                    <button type="button" @click="add('contacts', { type: 'whatsapp', value: '', label: '', icon: '', icon_url: '', active: true })" class="rounded-md border border-dashed border-slate-400 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-orange-400 hover:text-orange-600">+ Add contact</button>
                                </div>
                            </div>
                        </template>

                        <template x-if="errors.length">
                            <ul class="list-inside list-disc rounded-md border border-red-300 bg-red-50 p-3 text-red-700" data-editor-errors>
                                <template x-for="e in errors" :key="e"><li x-text="e"></li></template>
                            </ul>
                        </template>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-6 py-4">
                        <button type="button" @click="close()" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                        <button type="submit" :disabled="saving" class="rounded-md bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600 disabled:opacity-60" x-text="saving ? 'Saving…' : 'Save'"></button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            window.homepageEditor = function (content, urls, labels, uploadUrl) {
                return {
                    content, urls, labels, uploadUrl,
                    block: null,
                    draft: {},
                    errors: [],
                    saving: false,
                    uploading: false,
                    // A card's own image: sent as soon as it is picked, so the
                    // card previews it and Save only has a path to store.
                    async uploadImage(item, event, field = 'image') {
                        const file = event.target.files && event.target.files[0];
                        if (! file || this.uploading) return;
                        this.uploading = true;
                        this.errors = [];
                        const form = new FormData();
                        form.append('image', file);
                        try {
                            const res = await fetch(this.uploadUrl, {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                },
                                body: form,
                            });
                            if (res.status === 422) {
                                const data = await res.json().catch(() => ({}));
                                this.errors = Object.values(data.errors || {}).flat();
                                if (! this.errors.length) this.errors = [data.message || 'That image was not accepted.'];
                                return;
                            }
                            if (! res.ok) throw new Error('Upload failed (' + res.status + ').');
                            const data = await res.json();
                            item[field] = data.path;
                            item[field + '_url'] = data.url;
                        } catch (e) {
                            this.errors = [e.message];
                        } finally {
                            this.uploading = false;
                            event.target.value = '';
                        }
                    },
                    open(block) {
                        if (! (block in this.content)) return;
                        this.block = block;
                        // A copy, so Cancel leaves the page's content untouched.
                        this.draft = JSON.parse(JSON.stringify(this.content[block]));
                        this.errors = [];
                    },
                    close() { this.block = null; },
                    add(list, item) { this.draft[list].push(item); },
                    remove(list, i) { this.draft[list].splice(i, 1); },
                    move(list, i, by) {
                        const j = i + by;
                        const a = this.draft[list];
                        if (j < 0 || j >= a.length) return;
                        [a[i], a[j]] = [a[j], a[i]];
                    },
                    async save() {
                        if (! this.block || this.saving) return;
                        this.saving = true;
                        this.errors = [];
                        try {
                            const res = await fetch(this.urls[this.block], {
                                method: 'PUT',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                },
                                body: JSON.stringify(this.draft),
                            });
                            if (res.status === 422) {
                                const data = await res.json().catch(() => ({}));
                                this.errors = Object.values(data.errors || {}).flat();
                                if (! this.errors.length) this.errors = [data.message || 'Please check the fields.'];
                                return;
                            }
                            if (! res.ok) throw new Error('Could not save (' + res.status + ').');
                            // Saved. Show the page as it is now stored.
                            window.location.reload();
                        } catch (e) {
                            this.errors = [e.message];
                        } finally {
                            this.saving = false;
                        }
                    },
                };
            };
        </script>
    @endif

    <script>
        // The two sliders above. Registered here, in the body, so it exists
        // before Alpine (a deferred module from the head) starts and reads
        // x-data="homeSlider()". Native scrolling does the moving: a swipe
        // on a phone, the arrows on a desktop, both snapping to cards.
        window.homeSlider = function () {
            return {
                canPrev: false,
                canNext: false,
                init() {
                    this.update();
                    new ResizeObserver(() => this.update()).observe(this.$refs.track);
                },
                update() {
                    const t = this.$refs.track;
                    this.canPrev = t.scrollLeft > 4;
                    this.canNext = t.scrollLeft + t.clientWidth < t.scrollWidth - 4;
                },
                prev() { this.$refs.track.scrollBy({ left: -this.$refs.track.clientWidth * 0.9, behavior: 'smooth' }); },
                next() { this.$refs.track.scrollBy({ left: this.$refs.track.clientWidth * 0.9, behavior: 'smooth' }); },
            };
        };
    </script>
@endsection
