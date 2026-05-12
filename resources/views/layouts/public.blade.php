<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', \App\Models\SiteSettings::current()->displayName())</title>
    <meta name="description" content="@yield('meta_description', \App\Models\SiteSettings::current()->metaDescription())">
    @if ($favicon = \App\Models\SiteSettings::current()->logoUrl())
        <link rel="icon" href="{{ $favicon }}">
        <link rel="apple-touch-icon" href="{{ $favicon }}">
    @endif
    <script src="https://cdn.tailwindcss.com"></script>
    @include('partials.tailwind-config')
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="flex min-h-screen flex-col bg-white text-slate-800 antialiased">
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
            <a href="{{ url('/') }}" class="flex items-center">
                <x-brand size="md" />
            </a>
            <nav class="flex items-center gap-3 text-sm">
                {{-- <a href="#contact" class="hidden text-slate-600 hover:text-slate-900 sm:inline">Contact</a> --}}
                <a href="{{ route('login') }}"
                   class="rounded-md bg-slate-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-800">
                    Login
                </a>
            </nav>
        </div>
    </header>

    <main class="flex-1">
        @yield('content')
    </main>

    <footer class="border-t border-slate-200 bg-slate-50 py-8">
        <div class="mx-auto max-w-6xl px-4 text-sm text-slate-500">
            <p>&copy; {{ date('Y') }} {{ \App\Models\SiteSettings::current()->displayName() }}. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
