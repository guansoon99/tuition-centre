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
            .prose-section table { border-collapse: collapse; margin: 0.75rem 0; width: 100%; color: #000; }
            .prose-section th, .prose-section td { border: 1px solid #000; padding: 0.375rem 0.5rem; text-align: left; vertical-align: top; color: #000; }
            .prose-section th { background: rgb(241 245 249); font-weight: 600; }
        </style>
    @endpush
@endonce

{{-- TEXT BLOCK — render the rich HTML inline. --}}
@if ($type === \App\Models\Material::TYPE_TEXT)
    <div class="flex gap-3 px-4 py-3">
        <span class="inline-flex h-8 w-8 flex-shrink-0 items-center justify-center text-black">
            {{-- Document with lines icon --}}
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
        </span>
        <div class="min-w-0 flex-1">
            @if (trim($material->title) !== '' && $material->title !== 'Text')
                <p class="truncate text-sm text-black">{{ $material->title }}</p>
            @endif
            @if (trim($material->body ?? '') !== '')
                <div class="prose-section mt-1 text-sm leading-relaxed text-black">
                    {!! $material->body !!}
                </div>
            @else
                <p class="text-xs italic text-slate-400">Empty text block.</p>
            @endif
        </div>
    </div>

{{-- COUNTDOWN — live timer ticking down to target_date. --}}
@elseif ($type === \App\Models\Material::TYPE_COUNTDOWN)
    <div class="px-4 py-4">
        <p class="mb-2 truncate text-sm text-black">{{ $material->title }}</p>
        @if ($material->target_date)
            <x-countdown-timer :target-date="$material->target_date" />
        @else
            <p class="text-sm italic text-black">No target date set.</p>
        @endif
    </div>

{{-- PDF / EXTERNAL LINK — clickable row. --}}
@else
    @php
        $isExternal = $type !== \App\Models\Material::TYPE_PDF;
        $href = $isExternal
            ? $material->external_url
            : route('materials.view', $material);
    @endphp

    <a href="{{ $href }}"
       @if ($isExternal) target="_blank" rel="noopener" @endif
       class="flex items-center gap-3 rounded-md px-3 py-2 text-sm hover:bg-slate-100">
        <span class="inline-flex h-8 w-8 flex-shrink-0 items-center justify-center text-black">
            @if ($type === \App\Models\Material::TYPE_PDF)
                {{-- PDF: document outline with "PDF" text inside --}}
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"
                          stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M14 2v6h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    <text x="12" y="18" text-anchor="middle" font-size="6.5" font-weight="800"
                          font-family="Arial, sans-serif" fill="currentColor">PDF</text>
                </svg>
            @elseif ($type === \App\Models\Material::TYPE_EXTERNAL_LINK)
                {{-- External link: chain icon --}}
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                </svg>
            @else
                {{-- Fallback: generic file icon --}}
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            @endif
        </span>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm text-black">{{ $material->title }}</p>
        </div>
    </a>
@endif
