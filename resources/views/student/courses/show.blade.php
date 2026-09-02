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
            {{-- Fold-up state, resolved server-side into $collapsedSectionIds.
                 A user's own choice always wins and is persisted per user,
                 cross-device, via POST /sections/{id}/toggle-fold. Sections
                 the user has never touched decide for themselves by date —
                 last week's fold away, this week's stay open. The DOM flips
                 optimistically; the POST is fire-and-forget. --}}
            <div class="space-y-4"
                 x-data="{
                     collapsedIds: {{ json_encode($collapsedSectionIds) }},
                     allIds: {{ json_encode($visibleSections->pluck('id')->values()) }},
                     isOpen(id) { return ! this.collapsedIds.includes(id); },
                     post(url, body) {
                         return fetch(url, {
                             method: 'POST',
                             headers: {
                                 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                 'Accept': 'application/json',
                                 'Content-Type': 'application/json',
                             },
                             body: body ? JSON.stringify(body) : undefined,
                         }).catch(() => {});
                     },
                     toggle(id) {
                         const i = this.collapsedIds.indexOf(id);
                         const collapsed = i === -1;
                         if (collapsed) this.collapsedIds.push(id);
                         else this.collapsedIds.splice(i, 1);
                         /* Send the state just rendered rather than asking
                            the server to flip its own. The bulk buttons below
                            move what is on screen without telling the server,
                            so the two can disagree — and the screen is what
                            the user is acting on. */
                         this.post('/sections/' + id + '/toggle-fold', { collapsed });
                     },
                     /* Deliberately not persisted. These two are a way to
                        see the whole course at once, not a preference — so
                        they change nothing on the server and the next visit
                        starts fresh from the date rule plus whatever
                        individual sections the user has actually chosen. */
                     foldAll(collapsed) {
                         this.collapsedIds = collapsed ? [...this.allIds] : [];
                     },
                 }">

                {{-- Only worth offering when there is more than one section to
                     act on. --}}
                @if ($visibleSections->count() > 1)
                    <div class="flex justify-end gap-2">
                        {{-- Both always visible. Hiding whichever would be a
                             no-op left exactly one on screen at any moment,
                             which reads as a single button that changes its
                             mind rather than two you can choose between. --}}
                        <button type="button" @click="foldAll(false)"
                                class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                            Expand all
                        </button>
                        <button type="button" @click="foldAll(true)"
                                class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                            Collapse all
                        </button>
                    </div>
                @endif

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
                                        @include('partials.material-item', ['material' => $material])
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
