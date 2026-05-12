@php
    $user = auth()->user();
    $name = $user?->name ?? '';
    $initials = collect(explode(' ', $name))
        ->filter()
        ->take(2)
        ->map(fn ($p) => strtoupper(mb_substr($p, 0, 1)))
        ->join('');
@endphp

<header class="sticky top-0 z-30 border-b border-slate-200 bg-white">
    <div class="flex h-14 items-center justify-between gap-3 px-3 sm:px-6">
        {{-- Left: hamburger + brand --}}
        <div class="flex items-center gap-3">
            <button type="button"
                    @click="sidebarOpen = ! sidebarOpen"
                    class="-ml-1 inline-flex h-9 w-9 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 lg:hidden"
                    aria-label="Toggle sidebar">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          x-show="!sidebarOpen" d="M4 6h16M4 12h16M4 18h16" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          x-show="sidebarOpen" d="M6 18L18 6M6 6l12 12" x-cloak />
                </svg>
            </button>

            <a href="{{ url('/') }}" class="flex items-center">
                <x-brand size="sm" />
            </a>
        </div>

        {{-- Right: user dropdown --}}
        <div class="relative" x-data="{ menuOpen: false }">
            <button type="button"
                    @click="menuOpen = ! menuOpen"
                    class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-900 text-xs font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2"
                    aria-label="User menu">
                {{ $initials ?: '?' }}
            </button>

            <div x-show="menuOpen"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 @click.outside="menuOpen = false"
                 @keydown.escape.window="menuOpen = false"
                 x-cloak
                 class="absolute right-0 top-full z-40 mt-2 w-56 origin-top-right rounded-md border border-slate-200 bg-white shadow-lg ring-1 ring-black/5">
                <div class="border-b border-slate-100 px-4 py-3">
                    <p class="text-xs text-slate-500">Signed in as</p>
                    <p class="truncate text-sm font-medium text-slate-900">{{ $user?->name }}</p>
                </div>

                <div class="py-1">
                    <a href="{{ route('account.show') }}"
                       class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                        <svg class="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        Account
                    </a>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                                class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">
                            <svg class="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
