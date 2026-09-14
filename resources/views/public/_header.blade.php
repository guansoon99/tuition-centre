{{-- The public header: the brand and the Login button. Shown sticky on
     the public page; not sticky inside the back office, where the admin
     bar already sits at the top. --}}
@php $sticky = $sticky ?? true; @endphp
    <header class="{{ $sticky ? 'sticky top-0 z-30 ' : '' }}border-b border-orange-100 bg-white/90 backdrop-blur">
        <div class="flex items-center justify-between gap-4 px-5 py-3 sm:px-8 lg:px-14 xl:px-24">
            <a href="{{ url('/') }}" class="flex items-center">
                <x-brand size="md" />
            </a>
            <nav class="flex items-center gap-6 text-sm font-medium text-slate-700" aria-label="Main">
                <a href="{{ route('login') }}"
                   class="rounded-full bg-orange-500 px-5 py-2 text-sm font-semibold text-white shadow-md shadow-orange-200 transition hover:bg-orange-600">
                    Login
                </a>
            </nav>
        </div>
    </header>
