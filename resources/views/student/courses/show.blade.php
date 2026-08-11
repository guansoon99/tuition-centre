@extends('layouts.app')

@section('title', $course->name)

@section('content')
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $course->name }}</h1>
            @if (auth()->user()?->canAny(['courses.manage_teachers', 'courses.manage_students', 'sections.manage']))
                <div class="mt-3">
                    <a href="{{ route('courses.edit', $course) }}"
                       class="inline-flex items-center rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800">
                        Manage course
                    </a>
                </div>
            @endif
        </div>

        @php
            $visibleSections = ($canManage ?? false)
                ? $course->sections
                : $course->sections->filter(fn ($s) => $s->isVisibleToStudents());
        @endphp

        @if ($visibleSections->isEmpty())
            <p class="rounded-md border border-slate-200 bg-white p-6 text-sm text-slate-500">
                No content has been published yet for this course.
            </p>
        @else
            {{-- Fold-up state: sections default to OPEN. Any section the user
                 has explicitly collapsed is persisted server-side (per user,
                 cross-device) via POST /sections/{id}/toggle-fold. The DOM
                 flips optimistically; the POST is fire-and-forget. --}}
            <div class="space-y-4"
                 x-data="{
                     collapsedIds: {{ json_encode($collapsedSectionIds) }},
                     isOpen(id) { return ! this.collapsedIds.includes(id); },
                     toggle(id) {
                         const i = this.collapsedIds.indexOf(id);
                         if (i === -1) this.collapsedIds.push(id);
                         else this.collapsedIds.splice(i, 1);
                         fetch('/sections/' + id + '/toggle-fold', {
                             method: 'POST',
                             headers: {
                                 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                 'Accept': 'application/json',
                             },
                         }).catch(() => {});
                     },
                 }">

                @foreach ($visibleSections as $section)
                    <article class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                        <header @click="toggle({{ $section->id }})"
                                class="flex cursor-pointer items-center gap-2 border-b border-slate-100 bg-slate-50 px-4 py-3 hover:bg-slate-100"
                                :class="isOpen({{ $section->id }}) ? '' : 'border-b-0'">
                            {{-- Chevron on the left, tree-view style: points down
                                 when open, right when closed. Reads left-to-right
                                 as "toggle → title". --}}
                            <svg class="h-4 w-4 flex-shrink-0 transition-transform"
                                 :class="isOpen({{ $section->id }}) ? 'rotate-0' : '-rotate-90'"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                            <h2 class="text-lg font-medium text-slate-900">
                                {{ $section->title }}
                                @unless ($section->is_published)
                                    <span class="ml-1 rounded bg-amber-100 px-1.5 text-xs text-amber-800">draft</span>
                                @endunless
                            </h2>
                        </header>

                        <div x-show="isOpen({{ $section->id }})" x-cloak>
                            @php
                                $visibleMaterials = ($canManage ?? false)
                                    ? $section->materials
                                    : $section->materials->where('is_published', true);
                            @endphp

                            @if ($visibleMaterials->isEmpty())
                                <p class="px-4 py-4 text-xs italic text-slate-400">Nothing here yet.</p>
                            @else
                                <div class="divide-y divide-slate-100">
                                    @foreach ($visibleMaterials as $material)
                                        <x-material-item :material="$material" />
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
@endsection
