@props(['course'])

<a href="{{ route('courses.show', $course) }}"
   class="group block overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm transition hover:border-slate-300 hover:shadow">
    <div class="aspect-[4/3] w-full bg-gradient-to-br from-slate-100 to-slate-200">
        @if ($course->banner_image)
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($course->banner_image) }}"
                 alt="" class="h-full w-full object-cover" loading="lazy">
        @else
            <div class="flex h-full items-center justify-center text-slate-400">
                <span class="font-mono text-2xl">{{ $course->code }}</span>
            </div>
        @endif
    </div>
    <div class="p-4">
        <p class="font-mono text-xs text-slate-500">{{ $course->code }}</p>
        <h3 class="mt-1 line-clamp-2 text-sm font-medium text-slate-900 group-hover:text-slate-700">
            {{ $course->name }}
        </h3>
    </div>
</a>
