@props(['section'])

<article class="overflow-hidden rounded-lg border border-slate-200 bg-white">
    <header class="border-b border-slate-100 bg-slate-50 px-4 py-3">
        <h2 class="text-base font-medium text-slate-900">
            {{ $section->title }}
            @unless ($section->isVisibleToStudents())
                <span class="ml-1 rounded bg-amber-100 px-1.5 text-xs text-amber-800">hidden</span>
            @endunless
        </h2>
    </header>

    @if (trim($section->description ?? '') !== '')
        <div class="whitespace-pre-line px-4 py-4 text-sm leading-relaxed text-slate-700">{{ trim($section->description) }}</div>
    @else
        <p class="px-4 py-4 text-xs italic text-slate-400">No text yet.</p>
    @endif
</article>
