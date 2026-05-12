<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ \App\Models\SiteSettings::current()->displayName() }} · @yield('title', 'Login')</title>
    <meta name="description" content="@yield('meta_description', \App\Models\SiteSettings::current()->metaDescription())">
    @if ($favicon = \App\Models\SiteSettings::current()->logoUrl())
        <link rel="icon" href="{{ $favicon }}">
        <link rel="apple-touch-icon" href="{{ $favicon }}">
    @endif
    <script src="https://cdn.tailwindcss.com"></script>
    @include('partials.tailwind-config')
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <main class="flex min-h-screen items-center justify-center p-4">
        <div class="w-full max-w-md">
            <div class="mb-8 flex justify-center">
                <x-brand size="lg" />
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                @yield('content')
            </div>
            <p class="mt-6 text-center text-xs text-slate-500">
                &copy; {{ date('Y') }} {{ \App\Models\SiteSettings::current()->displayName() }}
            </p>
        </div>
    </main>
</body>
</html>
