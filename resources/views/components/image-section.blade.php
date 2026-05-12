@props(['section'])

<article class="overflow-hidden rounded-lg border border-slate-200 bg-white">
    @if ($section->title)
        <header class="border-b border-slate-100 bg-slate-50 px-4 py-3">
            <h2 class="text-base font-medium text-slate-900">
                {{ $section->title }}
                @unless ($section->isVisibleToStudents())
                    <span class="ml-1 rounded bg-amber-100 px-1.5 text-xs text-amber-800">hidden</span>
                @endunless
            </h2>
        </header>
    @endif

    <div class="flex justify-center bg-slate-50">
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($section->image_path) }}"
             alt="{{ $section->title }}"
             class="block max-h-96 w-auto object-contain" />
    </div>
</article>
