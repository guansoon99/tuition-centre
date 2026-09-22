{{-- The public header: the brand and the Login button. Shown sticky on
     the public page; not sticky inside the back office, where the admin
     bar already sits at the top. --}}
@php $sticky = $sticky ?? true; @endphp
    <header class="{{ $sticky ? 'sticky top-0 z-30 ' : '' }}border-b border-orange-100 bg-white/90 backdrop-blur">
        {{-- Phones get the medium brand and a compact button that never
             wraps; from the sm breakpoint up, the large brand and the full
             size button. The brand may shrink (min-w-0) so a long site name
             wraps to a second line instead of pushing the button off. --}}
        <div class="flex items-center justify-between gap-4 px-5 py-4 sm:px-8 sm:py-5 lg:px-14 xl:px-24">
            <a href="{{ url('/') }}" class="flex min-w-0 items-center">
                <span class="sm:hidden"><x-brand size="md" /></span>
                <span class="hidden sm:inline-flex"><x-brand size="lg" /></span>
            </a>
            <nav class="flex shrink-0 items-center gap-6 text-sm font-medium text-slate-700" aria-label="Main">
                <a href="{{ route('login') }}" data-student-login
                   class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-full bg-orange-500 px-4 py-2 text-sm font-bold text-white shadow-lg shadow-orange-200 transition hover:bg-orange-600 sm:gap-3 sm:px-6 sm:py-3 sm:text-base">
                    {{-- person --}}
                    <svg class="h-4 w-4 sm:h-5 sm:w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 12a4.5 4.5 0 100-9 4.5 4.5 0 000 9zM3.75 20.25a8.25 8.25 0 0116.5 0 .75.75 0 01-.75.75H4.5a.75.75 0 01-.75-.75z"/></svg>
                    <span class="h-4 w-px bg-white/60 sm:h-5" aria-hidden="true"></span>
                    Student Login
                </a>
            </nav>
        </div>
    </header>
