@props(['material'])

@php
    $icon = match ($material->type) {
        \App\Models\Material::TYPE_PDF => 'PDF',
        \App\Models\Material::TYPE_EXTERNAL_LINK => 'LINK',
        \App\Models\Material::TYPE_VIDEO_LINK => 'VIDEO',
    };
    $isExternal = $material->type !== \App\Models\Material::TYPE_PDF;
    $href = $isExternal
        ? $material->external_url
        : route('materials.download', $material);
@endphp

<a href="{{ $href }}"
   @if ($isExternal) target="_blank" rel="noopener" @endif
   class="flex items-center gap-3 rounded-md px-3 py-2 text-sm hover:bg-slate-100">
    <span class="inline-flex w-12 justify-center rounded bg-slate-200 px-2 py-0.5 text-xs font-mono text-slate-700">
        {{ $icon }}
    </span>
    <div class="min-w-0 flex-1">
        <p class="truncate text-slate-900">{{ $material->title }}</p>
    </div>
</a>
