@props(['material'])

@php
    $type = $material->type;
@endphp

@once
    @push('head')
        <style>
            .prose-section h1 { font-size: 1.25rem; font-weight: 600; margin: 0.5rem 0; color: rgb(15 23 42); }
            .prose-section h2 { font-size: 1.125rem; font-weight: 600; margin: 0.5rem 0; color: rgb(15 23 42); }
            .prose-section h3 { font-size: 1rem; font-weight: 600; margin: 0.5rem 0; color: rgb(15 23 42); }
            .prose-section p  { margin: 0.5rem 0; }
            .prose-section a  { color: rgb(2 132 199); text-decoration: underline; }
            .prose-section ul { list-style: disc; padding-left: 1.5rem; margin: 0.5rem 0; }
            .prose-section ol { list-style: decimal; padding-left: 1.5rem; margin: 0.5rem 0; }
            .prose-section blockquote { border-left: 3px solid rgb(203 213 225); padding-left: 0.75rem; color: rgb(71 85 105); margin: 0.5rem 0; }
            .prose-section img { max-width: 100%; height: auto; border-radius: 0.375rem; margin: 0.5rem 0; }
            .prose-section pre, .prose-section code { background: rgb(241 245 249); padding: 0.1rem 0.3rem; border-radius: 0.25rem; font-family: monospace; }
            .prose-section pre { padding: 0.75rem; overflow-x: auto; }
            .prose-section hr { border-top: 1px solid rgb(203 213 225); margin: 0.75rem 0; }
            .prose-section .ql-align-center  { text-align: center; }
            .prose-section .ql-align-right   { text-align: right; }
            .prose-section .ql-align-justify { text-align: justify; }
            .prose-section .ql-align-center img { display: block; margin-left: auto; margin-right: auto; }
            .prose-section .ql-align-right img  { display: block; margin-left: auto; margin-right: 0; }
            .prose-section .ql-align-justify img{ display: block; margin-left: auto; margin-right: auto; }
        </style>
    @endpush
@endonce

{{-- TEXT BLOCK — render the rich HTML inline. --}}
@if ($type === \App\Models\Material::TYPE_TEXT)
    <div class="px-4 py-3">
        @if (trim($material->title) !== '' && $material->title !== 'Text')
            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $material->title }}</p>
        @endif
        @if (trim($material->body ?? '') !== '')
            <div class="prose-section text-sm leading-relaxed text-slate-700">
                {!! $material->body !!}
            </div>
        @else
            <p class="text-xs italic text-slate-400">Empty text block.</p>
        @endif
    </div>

{{-- COUNTDOWN — live timer ticking down to target_date. --}}
@elseif ($type === \App\Models\Material::TYPE_COUNTDOWN)
    <div class="px-4 py-4">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $material->title }}</p>
        @if ($material->target_date)
            <x-countdown-timer :target-date="$material->target_date" />
        @else
            <p class="text-xs italic text-slate-400">No target date set.</p>
        @endif
    </div>

{{-- PDF / EXTERNAL LINK / VIDEO LINK — clickable row. --}}
@else
    @php
        $icon = match ($type) {
            \App\Models\Material::TYPE_PDF => 'PDF',
            \App\Models\Material::TYPE_EXTERNAL_LINK => 'LINK',
            \App\Models\Material::TYPE_VIDEO_LINK => 'VIDEO',
            default => 'FILE',
        };
        $isExternal = $type !== \App\Models\Material::TYPE_PDF;
        $href = $isExternal
            ? $material->external_url
            : route('materials.view', $material);
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
@endif
