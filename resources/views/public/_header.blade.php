{{-- The public header: the brand and the Login button. Shown sticky on
     the public page; not sticky inside the back office, where the admin
     bar already sits at the top. --}}
@php $sticky = $sticky ?? true; @endphp
    <header class="{{ $sticky ? 'sticky top-0 z-30 ' : '' }}border-b border-orange-100 bg-white/90 backdrop-blur">
        <div class="flex items-center justify-between gap-4 px-5 py-5 sm:px-8 lg:px-14 xl:px-24">
            <a href="{{ url('/') }}" class="flex items-center">
                <x-brand size="lg" />
            </a>
            <nav class="flex items-center gap-6 text-sm font-medium text-slate-700" aria-label="Main">
                <a href="{{ route('login') }}" data-student-login
                   class="inline-flex items-center gap-3 rounded-full bg-orange-500 px-6 py-3 text-base font-bold text-white shadow-lg shadow-orange-200 transition hover:bg-orange-600">
                    {{-- person --}}
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 12a4.5 4.5 0 100-9 4.5 4.5 0 000 9zM3.75 20.25a8.25 8.25 0 0116.5 0 .75.75 0 01-.75.75H4.5a.75.75 0 01-.75-.75z"/></svg>
                    <span class="h-5 w-px bg-white/60" aria-hidden="true"></span>
                    Student Login
                </a>
            </nav>
        </div>
    </header>
