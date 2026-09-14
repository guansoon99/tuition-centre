<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', \App\Models\SiteSettings::current()->displayName())</title>
    <meta name="description" content="@yield('meta_description', \App\Models\SiteSettings::current()->metaDescription())">
    @if ($favicon = \App\Models\SiteSettings::current()->logoUrl())
        <link rel="icon" href="{{ $favicon }}">
        <link rel="apple-touch-icon" href="{{ $favicon }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col bg-white text-slate-800 antialiased">

    @include('public._header')

    <main class="flex-1">
        @yield('content')
    </main>

    @include('public._footer')
</body>
</html>
