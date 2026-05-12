@props(['size' => 'md', 'inverted' => false])

@php
    $settings = \App\Models\SiteSettings::current();

    $heightClass = match ($size) {
        'sm' => 'h-6',
        'lg' => 'h-12',
        default => 'h-8',
    };
    $textSize = match ($size) {
        'sm' => 'text-sm',
        'lg' => 'text-xl',
        default => 'text-base',
    };
    $textClass = $inverted ? 'text-white' : 'text-slate-900';

    $hasLogo = $settings->logoUrl() !== null;
    $hasName = filled($settings->name);
@endphp

<span class="inline-flex items-center gap-2">
    @if ($hasLogo)
        <img src="{{ $settings->logoUrl() }}"
             alt="{{ $settings->displayName() }}"
             class="{{ $heightClass }} w-auto" />
    @endif

    @if ($hasName)
        <span class="{{ $textSize }} font-semibold {{ $textClass }}">{{ $settings->name }}</span>
    @elseif (! $hasLogo)
        <span class="{{ $textSize }} font-semibold {{ $textClass }}">{{ config('app.name') }}</span>
    @endif
</span>
