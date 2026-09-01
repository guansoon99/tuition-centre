{{--
    One row in a section's material list.

    A plain partial rather than a Blade component on purpose: this renders
    once per material, and a course page with 72 of them was spending ~75% of
    its time here. Measured on identical markup, <x-component> costs ~47% more
    than @include — the per-instance class resolution and attribute-bag work
    adds up across dozens of iterations.

    Expects: $material
--}}

@php
    $type = $material->type;
@endphp

@once
    @push('head')
        <style>
            /*
                A pasted URL or any other long unbreakable run would otherwise
                set this block's minimum width to the length of that run, which
                the row cannot shrink below — so the row grows and pushes the
                edit button out of view. Breaking mid-word is the lesser evil.
                Chinese text already wraps anywhere and is unaffected.
            */
            .prose-section { overflow-wrap: break-word; word-break: break-word; }
            /* Wide tables scroll inside the row rather than stretching it. */
            .prose-section table { display: block; overflow-x: auto; max-width: 100%; }
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
                .prose-section img { max-width: 50%; }
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
@if ($type === \App\Models\Material::TYPE_MEDIA)
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
        <span class="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center text-black">
            <img src="{{ asset('images/icons/media.webp') }}" alt="Media"
                 class="h-7 w-7 object-contain" />
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
    {{-- The description sits outside the anchor, same as the PDF/Link note.
         The sanitizer allows links in a body, and an <a> inside an <a> is
         invalid: the parser closes the outer one where the inner begins, so
         the rest of the row falls out of the link, stops being clickable and
         escapes the flex column. Icon and title stay the link; the hover
         wrapper keeps the two reading as one row. --}}
    <div class="rounded-md hover:bg-slate-100">
        <a href="{{ route('materials.view', $material) }}"
           class="flex gap-3 px-3 py-2 text-sm">
            <span class="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center text-black">
                <img src="{{ asset('images/icons/assignment.webp') }}" alt="Assignment"
                     class="h-7 w-7 object-contain" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-black">
                    {{ $material->title ?: 'Assignment' }}
                </p>
            </div>
        </a>

        @if ($hasBody)
            {{-- Empty span mirrors the icon so the description lines up under
                 the title rather than under the icon. --}}
            <div class="flex gap-3 px-3 py-2 text-sm">
                <span class="h-10 w-10 flex-shrink-0"></span>
                <div class="prose-section min-w-0 flex-1 text-black">
                    {!! $body !!}
                </div>
            </div>
        @endif
    </div>

{{-- PDF / PAGE / EXTERNAL LINK — clickable row. --}}
@else
    @php
        $isPdf   = $type === \App\Models\Material::TYPE_PDF;
        $isPage  = $type === \App\Models\Material::TYPE_PAGE;
        $isExternal = ! $isPdf && ! $isPage;
        $href = $isExternal
            ? $material->external_url
            : route('materials.view', $material);

        // Optional note under a PDF or Link row. Page keeps its body for the
        // separate page it opens, so it is not repeated here.
        $noteBody = ($isPdf || $isExternal) ? ($material->body ?? '') : '';
        $noteHasMedia = (bool) preg_match('/<(img|video|iframe|audio|source|embed)\b/i', $noteBody);
        $noteText = preg_replace('/\s+/u', '', strip_tags(html_entity_decode($noteBody, ENT_QUOTES | ENT_HTML5)));
        $hasNote = $noteText !== '' || $noteHasMedia;
    @endphp

    {{-- The note sits outside the anchor on purpose: the sanitizer allows
         links in a body, and an <a> inside an <a> is invalid — browsers close
         the outer one early and the row stops working. Wrapping both in a
         hover container keeps the row looking like one thing. --}}
    <div class="rounded-md hover:bg-slate-100">
    <a href="{{ $href }}"
       @if ($isExternal) target="_blank" rel="noopener" @endif
       class="flex items-center gap-3 px-3 py-2 text-sm">
        <span class="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center text-black">
            @if ($type === \App\Models\Material::TYPE_PDF)
                <img src="{{ asset('images/icons/pdf.webp') }}" alt="PDF"
                     class="h-7 w-7 object-contain" />
            @elseif ($type === \App\Models\Material::TYPE_PAGE)
                {{-- Page shares the Media icon — same underlying "type" in the UI. --}}
                <img src="{{ asset('images/icons/media.webp') }}" alt="Page"
                     class="h-7 w-7 object-contain" />
            @elseif ($type === \App\Models\Material::TYPE_EXTERNAL_LINK)
                <img src="{{ asset('images/icons/url.webp') }}" alt="Link"
                     class="h-7 w-7 object-contain" />
            @else
                {{-- Fallback: generic file icon --}}
                <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            @endif
        </span>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm text-black">{{ $material->title }}</p>
        </div>
    </a>

    @if ($hasNote)
        {{-- Empty span mirrors the icon so the note lines up under the title
             rather than under the icon. --}}
        <div class="flex gap-3 px-3 py-2 text-sm">
            <span class="h-10 w-10 flex-shrink-0"></span>
            <div class="prose-section min-w-0 flex-1 text-black">
                {!! $noteBody !!}
            </div>
        </div>
    @endif
    </div>
@endif
