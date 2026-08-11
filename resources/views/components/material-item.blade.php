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
            @media (min-width: 640px) {
                .prose-section img { max-width: 45%; }
            }
            .prose-section video { max-width: 100%; height: auto; border-radius: 0.375rem; margin: 0.5rem 0; display: block; }
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
            /* Zero the outer margins so the body sits flush with the row's padding. */
            .prose-section > *:first-child { margin-top: 0; }
            .prose-section > *:last-child  { margin-bottom: 0; }
        </style>
    @endpush
@endonce

{{-- TEXT BLOCK — render the rich HTML inline. --}}
@if ($type === \App\Models\Material::TYPE_TEXT)
    @php
        // Quill leaves `<p><br></p>` or `<p>&nbsp;</p>` behind when the editor is
        // emptied — treat any tag/entity-only shell as empty. But an image-only
        // or video-only body IS real content, so check for embedded media first
        // before deciding the body is "empty" (strip_tags removes <img> etc).
        $body = $material->body ?? '';
        $hasMedia = (bool) preg_match('/<(img|video|iframe|audio|source|embed)\b/i', $body);
        $bodyText = preg_replace('/\s+/u', '', strip_tags(html_entity_decode($body, ENT_QUOTES | ENT_HTML5)));
        $bodyIsEmpty = $bodyText === '' && ! $hasMedia;
    @endphp
    <div class="flex gap-3 px-3 py-2">
        <span class="inline-flex h-8 w-8 flex-shrink-0 items-center justify-center text-black">
            {{-- Text: same document outline as the PDF icon, with lines inside --}}
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M14 2v6h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M8 14h8M8 18h5"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
        <div class="min-w-0 flex-1">
            @if (trim($material->title) !== '' && $material->title !== 'Text')
                <p class="truncate text-sm text-black">{{ $material->title }}</p>
            @endif
            @if (! $bodyIsEmpty)
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

{{-- ASSIGNMENT — clickable row with title + inline description. --}}
@elseif ($type === \App\Models\Material::TYPE_ASSIGNMENT)
    @php
        $body = $material->body ?? '';
        $hasMedia = (bool) preg_match('/<(img|video|iframe|audio|source|embed)\b/i', $body);
        $bodyText = preg_replace('/\s+/u', '', strip_tags(html_entity_decode($body, ENT_QUOTES | ENT_HTML5)));
        $hasBody = $bodyText !== '' || $hasMedia;
    @endphp
    <a href="{{ route('materials.view', $material) }}"
       class="flex gap-3 rounded-md px-3 py-2 text-sm hover:bg-slate-100">
        <span class="inline-flex h-8 w-8 flex-shrink-0 items-center justify-center text-black">
            {{-- Clipboard-with-check icon --}}
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M9 2h6a1 1 0 011 1v2H8V3a1 1 0 011-1z"
                      stroke="currentColor" stroke-width="2" stroke-linejoin="round" />
                <path d="M8 5H6a2 2 0 00-2 2v13a2 2 0 002 2h12a2 2 0 002-2V7a2 2 0 00-2-2h-2"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M9 13l2 2 4-4" stroke="currentColor" stroke-width="2"
                      stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
        <div class="min-w-0 flex-1">
            <p class="truncate text-black">
                {{ $material->title ?: 'Assignment' }}
            </p>
            @if ($hasBody)
                <div class="prose-section mt-1 text-black">
                    {!! $body !!}
                </div>
            @endif
        </div>
    </a>

{{-- PDF / PAGE / EXTERNAL LINK — clickable row. --}}
@else
    @php
        $isPdf   = $type === \App\Models\Material::TYPE_PDF;
        $isPage  = $type === \App\Models\Material::TYPE_PAGE;
        $isExternal = ! $isPdf && ! $isPage;
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
                          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M14 2v6h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    <text x="12" y="18.5" text-anchor="middle" font-size="7" font-weight="800"
                          font-family="Arial, sans-serif" fill="currentColor">PDF</text>
                </svg>
            @elseif ($type === \App\Models\Material::TYPE_PAGE)
                {{-- Page: same icon as Text (they're the same underlying "type" in the UI) --}}
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"
                          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M14 2v6h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M8 14h8M8 18h5"
                          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
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
