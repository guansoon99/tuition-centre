@extends('layouts.app')

@section('title', ($material->title ?: 'Assignment').' — submissions')

@section('content')
    @php
        $isPastDue = $material->isPastDue();

        $submittedCount = $students->filter(function ($s) use ($submissions) {
            $sub = $submissions->get($s->id);
            return $sub && $sub->files->isNotEmpty();
        })->count();
        $totalCount = $students->count();
        $ungradedCount = $submissions->filter(fn ($sub) => $sub->files->isNotEmpty() && ! $sub->isGraded())->count();
    @endphp

    <div class="mx-auto max-w-6xl space-y-4">
        {{-- Breadcrumb --}}
        <div>
            <a href="{{ route('courses.edit', [$material->section->course, 'tab' => 'materials']) }}"
               class="text-base text-slate-900">
                &larr; Back To Course
            </a>
        </div>

        {{-- Title and progress on one line. The header card that used to hold
             these is gone: the deadline moved into the description card below,
             and the upload limits are set on the material itself, so nothing
             was left for the card to carry. --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            @include('partials.assignment-title')

        {{-- Pushed from here rather than from the modal fragment: a fetched
             fragment cannot add to the head stack, so the table inside the
             modal would arrive unstyled. --}}
        @include('partials.detail-table-styles')

            <div class="flex flex-shrink-0 gap-2 text-center">
                <div class="rounded-md bg-slate-100 px-3 py-2">
                    <p class="text-xl font-bold text-slate-900">{{ $submittedCount }}<span class="text-sm font-normal text-slate-700">/{{ $totalCount }}</span></p>
                    <p class="text-xs text-slate-600">Submitted</p>
                </div>
                <div class="rounded-md bg-amber-100 px-3 py-2">
                    <p class="text-xl font-bold text-amber-900">{{ $ungradedCount }}</p>
                    <p class="text-xs text-amber-800">To Grade</p>
                </div>
            </div>
        </div>

        {{-- The brief, exactly as the students see it. --}}
        @include('partials.assignment-description', ['showDue' => true])

        {{-- Roster --}}
        @if ($students->isEmpty())
            <div class="rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-700">
                No enrolled students in this course yet.
            </div>
        @else
            {{-- openGradeFor lives out here, not on the spaced stack below.
                 space-y-3 puts margin-top on every child after the first, and a
                 margin on a position:fixed overlay with inset-0 shifts it down —
                 leaving a strip of the page showing above it. Keeping the modal
                 outside that stack keeps inset-0 meaning what it says. --}}
            <div x-data="{ openGradeFor: null }">
            <div x-data="{ status: 'all', search: '' }" class="space-y-3">
                {{-- Filter bar (client-side, no reload) --}}
                <div class="flex flex-wrap items-center gap-3 rounded-md border border-slate-200 bg-white p-3">
                    <select x-model="status"
                            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        <option value="all">All</option>
                        <option value="submitted">Submitted</option>
                        <option value="not-submitted">Not Submitted</option>
                        <option value="to-grade">To Grade</option>
                    </select>
                    <input type="text" x-model="search" placeholder="Search name or username…"
                           class="min-w-[12rem] flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
                    <button type="button"
                            @click="status = 'all'; search = ''"
                            class="rounded-md bg-red-500 px-3 py-1.5 text-sm text-white hover:bg-red-600">
                        Clear
                    </button>
                    {{-- Download asks what to download. Always shown, even with
                         nothing handed in: that is exactly when the status list
                         is worth having. --}}
                    <div class="relative" x-data="{ downloadOpen: false }">
                        <button type="button"
                                @click="downloadOpen = ! downloadOpen"
                                class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                            </svg>
                            Download
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <div x-show="downloadOpen"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             @click.outside="downloadOpen = false"
                             @keydown.escape.window="downloadOpen = false"
                             x-cloak
                             class="absolute right-0 top-full z-40 mt-2 w-64 origin-top-right rounded-md border border-slate-200 bg-white shadow-lg ring-1 ring-black/5">
                            <div class="py-1">
                                @if ($submittedCount > 0)
                                    <a href="{{ route('submissions.download-all', $material) }}"
                                       @click="downloadOpen = false"
                                       class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                        <span class="font-medium text-slate-900">Files</span>
                                        <span class="block text-xs text-slate-600">Every submitted file, as a ZIP</span>
                                    </a>
                                @else
                                    {{-- Shown rather than hidden, so the option
                                         does not appear to be missing. --}}
                                    <p class="block cursor-not-allowed px-4 py-2 text-sm">
                                        <span class="font-medium text-slate-600">Files</span>
                                        <span class="block text-xs text-slate-600">Nothing submitted yet</span>
                                    </p>
                                @endif

                                <a href="{{ route('submissions.download-status', $material) }}"
                                   @click="downloadOpen = false"
                                   class="block border-t border-slate-100 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                    <span class="font-medium text-slate-900">Status</span>
                                    <span class="block text-xs text-slate-600">Who has submitted, as Excel</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                @foreach ($students as $student)
                    @php
                        $submission = $submissions->get($student->id);
                        $files = $submission?->files ?? collect();
                        $hasSubmitted = $submission && $files->isNotEmpty();
                        $isMissing = ! $hasSubmitted && $isPastDue;
                        $isToGrade = $hasSubmitted && ! $submission->isGraded();
                        $rowStatus = $hasSubmitted ? 'submitted' : 'not-submitted';
                        $searchable = strtolower($student->name.' '.$student->username);
                    @endphp

                    <div x-show="(status === 'all'
                                  || status === '{{ $rowStatus }}'
                                  || (status === 'to-grade' && {{ $isToGrade ? 'true' : 'false' }}))
                              && (search === '' || @js($searchable).includes(search.toLowerCase()))"
                         @class([
                        'rounded-lg border bg-white p-4',
                        'border-red-200 bg-red-50' => $isMissing,
                        'border-slate-200' => ! $isMissing,
                    ])>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">
                                    {{ $student->name }}
                                </p>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                    {{-- Same wording as the student's own status
                                         table, so the two sides describe one
                                         submission the same way. --}}
                                    @if ($hasSubmitted)
                                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-700">
                                            Submitted for grading
                                        </span>
                                    @elseif ($isMissing)
                                        <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 font-medium text-red-700">
                                            Missing
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-600">
                                            Not submitted
                                        </span>
                                    @endif

                                    {{-- Shown either way, matching the student's
                                         Grading status row. The mark itself is
                                         in the modal rather than the badge. --}}
                                    @if ($hasSubmitted || $submission?->isGraded())
                                        @if ($submission?->isGraded())
                                            <span class="inline-flex items-center rounded-full bg-sky-100 px-2 py-0.5 font-medium text-sky-800">
                                                Graded
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-700">
                                                Not Graded
                                            </span>
                                        @endif
                                    @endif

                                    {{-- Counts and the last-modified time used to
                                         sit here. Both are in the modal — the
                                         File submission and Last modified rows —
                                         so the row is left as status alone. --}}
                                </div>
                            </div>

                            {{-- Everything else — the files, the grade, the
                                 comment and the feedback — is behind this button.
                                 Four stacked controls per student made a class of
                                 thirty unreadable. --}}
                            @if ($submission && ($files->isNotEmpty() || $submission->isGraded()))
                                <button type="button"
                                        @click="openGradeFor = openGradeFor === {{ $submission->id }} ? null : {{ $submission->id }}"
                                        class="flex-shrink-0 rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                                    <span x-text="openGradeFor === {{ $submission->id }} ? 'Close' : 'Grade'">Grade</span>
                                </button>
                            @endif
                        </div>

                        {{-- Opens in place, pushing the rest of the roster down,
                             rather than over the page.

                             Still fetched on open: one panel per row is cheap
                             while empty, but thirty grade forms and thirty file
                             inputs in the page is what this avoids. --}}
                        @if ($submission && ($files->isNotEmpty() || $submission->isGraded()))
                            <div x-data="gradeDetail()"
                                 x-init="$watch('openGradeFor', id => {
                                     if (id === {{ $submission->id }}) { load(id); }
                                     else if (loadedId !== null) { close(); }
                                 })"
                                 x-show="openGradeFor === {{ $submission->id }}"
                                 x-cloak
                                 class="mt-3 border-t border-slate-200 pt-3">
                                <div x-show="loading" class="py-6 text-center text-sm text-slate-600">Loading…</div>
                                <div x-show="failed" x-cloak class="py-6 text-center text-sm text-red-600">
                                    Couldn't load this submission.
                                    <button type="button" @click="load(openGradeFor)" class="underline">Retry</button>
                                </div>

                                {{-- Outside the body, which is replaced on every
                                     reload and would take the message with it. --}}
                                <div x-show="error" x-cloak
                                     class="mb-3 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700"
                                     x-text="error"></div>

                                <div x-ref="body" x-show="! loading && ! failed"></div>
                            </div>
                        @endif
                    </div>
                @endforeach
                </div>
            </div>

            {{--
                KEPT, NOT DELETED.

                This is the overlay version of the same panel, parked behind a
                false condition while the inline one is tried out. A disabled
                condition rather than a Blade comment, because the block carries
                comments of its own and Blade comments do not nest — the outer
                one would end at the first inner close marker.

                Delete this once the inline version has been lived with, or
                flip the condition to bring it back.
            --}}
            @if (false)
                {{-- One grading modal for the whole roster, body fetched on
                     open. Rendering it per student would ship the form, and a
                     file input, thirty times over — the same reason the
                     material edit modal is built this way. --}}
                <div x-show="openGradeFor !== null" x-cloak
                     x-data="gradeDetail()"
                     x-init="$watch('openGradeFor', id => id === null ? close() : load(id))"
                     x-effect="lockScroll(openGradeFor !== null)"
                     @keydown.escape.window="openGradeFor = null"
                     {{-- The scroller IS the backdrop.
                          A separate position:fixed backdrop sits outside this
                          element's scroll chain, so a wheel over it targets the
                          document — which lockScroll has frozen — and nothing
                          moves until you happen to point at the panel itself.
                          One element carrying both roles keeps the whole
                          overlay scrollable from anywhere.
                          click.self so only the backdrop area closes it, not
                          clicks that bubble up from inside the panel. --}}
                     @click.self="openGradeFor = null"
                     class="fixed inset-0 z-40 overflow-y-auto bg-black/40 p-4">
                    <div class="relative mx-auto mt-12 w-full max-w-xl rounded-lg bg-white p-6 shadow-xl">
                        <div x-show="loading" class="py-10 text-center text-sm text-slate-600">Loading…</div>
                        <div x-show="failed" x-cloak class="py-10 text-center text-sm text-red-600">
                            Couldn't load this submission.
                            <button type="button" @click="load(openGradeFor)" class="underline">Retry</button>
                        </div>
                        {{-- On the shell, not in the body: the body is replaced
                             on every reload and would take the message with it. --}}
                        <div x-show="error" x-cloak
                             class="mb-3 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700"
                             x-text="error"></div>


                        <div x-ref="body" x-show="! loading && ! failed"></div>
                    </div>
                </div>
            </div>
            @endif
        @endif
    </div>
@endsection

@push('scripts')
<script>
    /*
     * Fetches the grading form for one submission.
     *
     * Serves both layouts: the inline panel in each row, and the overlay kept
     * behind a disabled condition above.
     *
     * Same shape as materialEditModal() on the course page, minus the pieces
     * that page needs and this one does not (flatpickr, TomSelect). Kept local
     * rather than shared because that bundle is admin-only.
     */
    window.gradeDetail = function () {
        return {
            loading: false,
            failed: false,
            loadedId: null,
            error: '',
            // Something was saved while the modal was open, so the roster row
            // behind it is out of date.
            dirty: false,

            reset() {
                this.$refs.body.innerHTML = '';
                this.loadedId = null;
                this.failed = false;
                this.loading = false;
                this.error = '';
            },

            /*
             * Closing.
             *
             * Nothing reloads the page any more, so a grade saved or a file
             * attached leaves the roster row behind showing the old badge and
             * counts. Refreshing on the way out keeps the list honest without
             * interrupting the work.
             */
            close() {
                if (this.dirty) {
                    window.location.reload();

                    return;
                }

                this.reset();
            },

            /*
             * Stop the page behind the modal from scrolling.
             *
             * Without this the wheel scrolls the roster underneath instead of
             * the modal, which is disorienting on a long class list.
             *
             * The padding compensates for the scrollbar the lock removes;
             * without it the page jumps sideways as the modal opens. Width is
             * measured before the class is applied, since afterwards the
             * scrollbar is already gone and the difference reads as zero.
             */
            lockScroll(on) {
                const scrollbar = window.innerWidth - document.documentElement.clientWidth;

                document.body.style.paddingRight = on ? `${scrollbar}px` : '';
                document.body.classList.toggle('overflow-hidden', on);
            },

            /*
             * Re-fetch the body for the submission already open.
             *
             * load() short-circuits when asked for the id it already holds, so
             * the marker has to be cleared first or this would do nothing.
             */
            async reload() {
                const id = this.loadedId;
                this.loadedId = null;
                await this.load(id);
            },

            /*
             * Post a form from inside the modal without leaving it.
             *
             * These forms would otherwise redirect, which reloads the page and
             * shuts the modal — losing the teacher's place in a long roster
             * every time they attach a file.
             */
            async submitInPlace(form, closeAfter = false) {
                this.error = '';

                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!res.ok) {
                        const data = await res.json().catch(() => ({}));
                        this.error = data.message
                            || (data.errors && Object.values(data.errors)[0][0])
                            || 'That did not work.';

                        return;
                    }

                    this.dirty = true;

                    // Saving the grade is the end of the job for this student,
                    // so it closes — and close() refreshes the page, which is
                    // what brings the row's badge up to date. Attaching a file
                    // is not, so that stays open with the body re-rendered.
                    if (closeAfter) {
                        this.openGradeFor = null;

                        return;
                    }

                    await this.reload();
                } catch (e) {
                    this.error = 'Could not reach the server.';
                }
            },

            async load(id) {
                if (id === null || id === this.loadedId) return;

                this.loading = true;
                this.failed = false;
                this.$refs.body.innerHTML = '';

                try {
                    const res = await fetch(`/submissions/${id}/grade-modal`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!res.ok) throw new Error(res.status);

                    this.$refs.body.innerHTML = await res.text();
                    this.loadedId = id;

                    // The fragment carries its own x-data, so Alpine has to be
                    // told to walk the newly inserted subtree.
                    window.Alpine.initTree(this.$refs.body);
                } catch (e) {
                    this.failed = true;
                } finally {
                    this.loading = false;
                }
            },
        };
    };
</script>
@endpush
