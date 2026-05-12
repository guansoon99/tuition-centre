@props(['title' => null, 'subtitle' => null])

<section class="rounded-lg bg-gradient-to-r from-slate-900 to-slate-700 px-6 py-8 text-white shadow">
    <h1 class="text-2xl font-semibold tracking-tight">{{ $title ?? 'Welcome to '.\App\Models\SiteSettings::current()->displayName() }}</h1>
    @if ($subtitle)
        <p class="mt-1 text-sm text-slate-200">{{ $subtitle }}</p>
    @endif
</section>
