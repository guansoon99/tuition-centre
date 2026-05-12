@extends('layouts.public')

@section('title', \App\Models\SiteSettings::current()->displayName())

@section('content')
    {{-- Banner / slideshow --}}
    @if ($slides->isNotEmpty())
        <section class="relative bg-slate-900"
                 x-data="{
                    current: 0,
                    total: {{ $slides->count() }},
                    paused: false,
                    next() { this.current = (this.current + 1) % this.total },
                    prev() { this.current = (this.current - 1 + this.total) % this.total },
                 }"
                 x-init="setInterval(() => { if (! paused && total > 1) next() }, 5000)">
            <div class="relative mx-auto w-full max-w-5xl overflow-hidden" style="aspect-ratio: 4/3;">
                @foreach ($slides as $i => $slide)
                    <div x-show="current === {{ $i }}"
                         x-transition:enter="transition-opacity duration-700"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         class="absolute inset-0">
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($slide->image_path) }}"
                             alt="{{ $slide->title }}"
                             class="h-full w-full object-contain" />
                    </div>
                @endforeach

                @if ($slides->count() > 1)
                    <button @click="prev(); paused = true"
                            class="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-black/40 p-2 text-white hover:bg-black/60 sm:left-6"
                            aria-label="Previous slide">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <button @click="next(); paused = true"
                            class="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-black/40 p-2 text-white hover:bg-black/60 sm:right-6"
                            aria-label="Next slide">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </button>
                @endif
            </div>

            @if ($slides->count() > 1)
                <div class="flex justify-center gap-2 py-4">
                    @foreach ($slides as $i => $_)
                        <button @click="current = {{ $i }}; paused = true"
                                :class="current === {{ $i }} ? 'w-6 bg-white' : 'w-2 bg-white/40 hover:bg-white/70'"
                                class="h-2 rounded-full transition-all"
                                aria-label="Go to slide {{ $i + 1 }}"></button>
                    @endforeach
                </div>
            @endif
        </section>
    @else
        {{-- Fallback: gradient hero when no slides uploaded --}}
        <section class="relative overflow-hidden bg-gradient-to-br from-slate-900 via-slate-800 to-slate-700 text-white">
            <div class="absolute inset-0 opacity-20" aria-hidden="true">
                <div class="absolute -left-20 top-10 h-64 w-64 rounded-full bg-amber-300 blur-3xl"></div>
                <div class="absolute -right-20 bottom-0 h-72 w-72 rounded-full bg-sky-400 blur-3xl"></div>
            </div>
            <div class="relative mx-auto max-w-6xl px-4 py-24 sm:py-32">
                <p class="text-xs uppercase tracking-[0.2em] text-amber-200">{{ \App\Models\SiteSettings::current()->displayName() }}</p>
                <h1 class="mt-3 max-w-3xl text-4xl font-semibold leading-tight sm:text-5xl">
                    Selamat datang.
                </h1>
                <p class="mt-5 max-w-2xl text-base text-slate-200 sm:text-lg">
                    Belajar dengan teratur, dapat keputusan yang lebih baik.
                </p>
                <div class="mt-8">
                    <a href="{{ route('login') }}"
                       class="rounded-md bg-amber-400 px-5 py-2.5 text-sm font-medium text-slate-900 hover:bg-amber-300">
                        Login to your account
                    </a>
                </div>
            </div>
        </section>
    @endif

    {{--
        Contact section disabled. Uncomment to re-enable.

        @php $settings = \App\Models\SiteSettings::current(); @endphp
        @if ($settings->contact_phone || $settings->contact_address || $settings->contact_hours)
            <section id="contact" class="py-16">
                <div class="mx-auto max-w-3xl px-4 text-center">
                    <h2 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Contact us</h2>
                    <div class="mt-6 flex flex-col items-center gap-2 text-sm text-slate-700">
                        @if ($settings->contact_phone)
                            <p>📞 <strong>Phone:</strong> {{ $settings->contact_phone }}</p>
                        @endif
                        @if ($settings->contact_address)
                            <p class="whitespace-pre-line">📍 <strong>Address:</strong> {{ $settings->contact_address }}</p>
                        @endif
                        @if ($settings->contact_hours)
                            <p>🕘 <strong>Hours:</strong> {{ $settings->contact_hours }}</p>
                        @endif
                    </div>
                </div>
            </section>
        @endif
    --}}
@endsection
