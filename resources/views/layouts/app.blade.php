<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ \App\Models\SiteSettings::current()->displayName() }} · @yield('title', 'Home')</title>
    <meta name="description" content="@yield('meta_description', \App\Models\SiteSettings::current()->metaDescription())">
    @if ($favicon = \App\Models\SiteSettings::current()->logoUrl())
        <link rel="icon" href="{{ $favicon }}">
        <link rel="apple-touch-icon" href="{{ $favicon }}">
    @endif
    <script src="https://cdn.tailwindcss.com"></script>
    @include('partials.tailwind-config')
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        /* Replace the browser-default select arrow with the same chevron used on our Tom Select controls.
           iOS Safari applies a gray system background to <select> by default — force white to match other inputs. */
        select {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-color: #fff;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%2364748b' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 8px center;
            background-size: 18px;
            padding-right: 2rem !important;
        }
    </style>
    @stack('head')
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased" x-data="{ sidebarOpen: false }">
    @auth
        <x-topbar />

        <div class="flex">
            <x-sidebar />

            <div class="min-w-0 flex-1">
                @if (session('status'))
                    <div class="border-b border-emerald-200 bg-emerald-50 px-6 py-2 text-sm text-emerald-800">
                        {{ session('status') }}
                    </div>
                @endif

                <main class="px-4 py-6 sm:px-6 lg:px-8">
                    @yield('content')
                </main>
            </div>
        </div>

        <x-contact-floater />
    @else
        <main class="mx-auto max-w-6xl px-4 py-6">
            @yield('content')
        </main>
    @endauth
    @stack('scripts')
</body>
</html>
