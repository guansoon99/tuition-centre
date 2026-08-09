@extends('layouts.app')

@section('title', 'Calendar')

@section('content')
    @php
        $canCreate = auth()->user()?->can('calendar.create');
        $canEdit = auth()->user()?->can('calendar.edit');
        $canDelete = auth()->user()?->can('calendar.delete');
    @endphp

    <div class="space-y-4"
         x-data="calendarPage({
             canCreate: {{ $canCreate ? 'true' : 'false' }},
             canEdit: {{ $canEdit ? 'true' : 'false' }},
             canDelete: {{ $canDelete ? 'true' : 'false' }},
             feedUrl: '{{ route('calendar.events') }}',
             storeUrl: '{{ $canCreate ? route('calendar.events.store') : '' }}',
             csrf: '{{ csrf_token() }}',
             palette: window.EVENT_COLORS,
             defaultColor: '{{ \App\Models\Event::COLOR_DEFAULT }}',
         })">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold text-slate-900">Calendar</h1>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 sm:p-6">
            {{-- Custom header row above the FullCalendar table --}}
            <div class="mb-3 flex flex-wrap items-center gap-3">
                {{-- View switcher — matches the radio-pill pattern used on type selectors elsewhere in the app. --}}
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="setView('dayGridMonth')"
                            :class="view === 'dayGridMonth'
                                ? 'border-slate-900 bg-slate-900 text-white'
                                : 'border-slate-300 text-slate-700 hover:bg-slate-50'"
                            class="cursor-pointer rounded-md border px-3 py-2 text-sm">
                        Month
                    </button>
                    <button type="button" @click="setView('listUpcoming')"
                            :class="view === 'listUpcoming'
                                ? 'border-slate-900 bg-slate-900 text-white'
                                : 'border-slate-300 text-slate-700 hover:bg-slate-50'"
                            class="cursor-pointer rounded-md border px-3 py-2 text-sm">
                        Upcoming
                    </button>
                </div>
            </div>

            {{-- Prev-month-name / Title / Next-month-name (Moodle-style) — hidden in Upcoming list view. --}}
            <div x-show="view !== 'listUpcoming'"
                 class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <button type="button" @click="calendar.prev()"
                        class="inline-flex items-center gap-1.5 text-base text-slate-900">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                    </svg>
                    <span x-text="prevMonthName"></span>
                </button>
                <h2 x-text="currentMonthName"
                    class="text-lg font-semibold text-slate-700 sm:text-xl"></h2>
                <button type="button" @click="calendar.next()"
                        class="inline-flex items-center gap-1.5 text-base text-slate-900">
                    <span x-text="nextMonthName"></span>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>

            <div id="calendar"></div>
        </div>

        {{-- Event modal (create / view / edit).
             x-teleport moves the DOM node to the end of <body>, escaping any
             stacking context created by the layout (sticky topbar, flex, etc.)
             so the backdrop reliably covers the whole viewport. --}}
        <template x-teleport="body">
        <div x-show="modal.open" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
             @click.self="closeModal()">
            <div class="w-full max-w-md rounded-lg bg-white shadow-lg">
                <div class="border-b border-slate-200 px-5 py-3">
                    <h2 class="text-base font-semibold text-slate-900"
                        x-text="modal.isHoliday ? 'Public Holiday' : (modal.id ? 'Event' : 'New Event')"></h2>
                </div>

                <div class="space-y-3 px-5 py-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-700">Title</label>
                        <input type="text" x-model="modal.title" maxlength="200"
                               :readonly="! canModify"
                               :class="canModify ? 'bg-white' : 'bg-slate-100 cursor-not-allowed'"
                               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                        <p x-show="errors.title" x-text="errors.title" class="mt-1 text-xs text-red-600"></p>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-700">Date</label>
                        <input type="text" x-ref="dateInput" x-model="modal.date" size="1"
                               placeholder="YYYY-MM-DD" data-flatpickr-date
                               :readonly="! canModify"
                               :class="canModify ? 'bg-white' : 'bg-slate-100 cursor-not-allowed'"
                               class="w-full min-w-0 rounded-md border border-slate-300 px-3 py-2 font-mono text-sm" />
                        <p x-show="errors.date" x-text="errors.date" class="mt-1 text-xs text-red-600"></p>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-700">Color</label>
                        <div class="flex flex-wrap gap-2">
                            {{-- Alpine's x-for only iterates arrays, not plain objects.
                                 Object.entries turns {blue:'#3b82f6',...} into
                                 [['blue','#3b82f6'],...] which x-for handles. --}}
                            <template x-for="[slug, hex] in Object.entries(palette)" :key="slug">
                                <button type="button"
                                        @click="if (canModify) modal.color = slug"
                                        :disabled="! canModify"
                                        :title="slug"
                                        :class="modal.color === slug ? 'ring-2 ring-offset-2 ring-slate-900' : 'ring-1 ring-slate-300'"
                                        :style="`background:${hex}`"
                                        class="h-7 w-7 rounded-full transition disabled:cursor-not-allowed"></button>
                            </template>
                        </div>
                        <p x-show="errors.color" x-text="errors.color" class="mt-1 text-xs text-red-600"></p>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-3">
                    {{-- Delete on the left (existing event only) --}}
                    <div>
                        <template x-if="modal.id && canDelete && ! modal.isHoliday">
                            <button type="button" @click="deleteEvent()"
                                    class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700">
                                Delete
                            </button>
                        </template>
                    </div>

                    <div class="flex gap-2">
                        <button type="button" @click="closeModal()"
                                class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                            <span x-text="canModify ? 'Cancel' : 'Close'"></span>
                        </button>

                        <template x-if="canModify">
                            <button type="button" @click="saveEvent()"
                                    class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800">
                                Save
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
        </template>
    </div>
@endsection

@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
    <style>
        /* Moodle-style airy calendar grid. */
        .fc {
            --fc-border-color: rgb(226 232 240);         /* slate-200 */
            --fc-page-bg-color: #fff;
            --fc-neutral-bg-color: #fff;
            --fc-list-event-hover-bg-color: rgb(241 245 249);
            --fc-today-bg-color: rgb(254 249 195);        /* subtle amber for today */
        }
        /* Day-of-week header row: plain, bold, small caps-ish. */
        .fc .fc-col-header-cell-cushion {
            padding: 10px 8px;
            font-weight: 600;
            font-size: 0.8rem;
            color: rgb(51 65 85);                         /* slate-700 */
            text-decoration: none;
        }
        .fc .fc-col-header-cell {
            background: #fff;
        }
        /* Day cells: airy padding, top-aligned number. */
        .fc .fc-daygrid-day-frame {
            min-height: 84px;
            padding: 4px 6px;
        }
        .fc .fc-daygrid-day-number {
            padding: 4px 4px 0 4px;
            font-size: 0.85rem;
            color: rgb(51 65 85);                         /* slate-700 */
            text-decoration: none;
        }
        /* Sunday column — muted rose to signal weekend. */
        .fc .fc-day-sun .fc-daygrid-day-number {
            color: rgb(244 63 94);                        /* rose-500 */
        }
        /* Event pills — slimmer, cleaner. */
        .fc .fc-daygrid-event {
            border-radius: 4px;
            padding: 1px 6px;
            font-size: 0.75rem;
            border: 0;
        }
        /* List view rows */
        .fc .fc-list-event-title,
        .fc .fc-list-event-time {
            font-size: 0.85rem;
        }
    </style>
@endpush

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script>
        // Emit the palette as a JS global, kept out of the x-data attribute
        // so Blade output doesn't collide with the attribute's quotes.
        window.EVENT_COLORS = {!! json_encode(\App\Models\Event::COLORS) !!};

        function calendarPage(opts) {
            return {
                calendar: null,
                fp: null,
                canCreate: opts.canCreate,
                canEdit: opts.canEdit,
                canDelete: opts.canDelete,
                feedUrl: opts.feedUrl,
                storeUrl: opts.storeUrl,
                csrf: opts.csrf,
                palette: opts.palette,
                defaultColor: opts.defaultColor,
                view: 'dayGridMonth',
                currentMonthName: '',
                prevMonthName: '',
                nextMonthName: '',
                modal: { open: false, id: null, title: '', date: '', color: opts.defaultColor, isHoliday: false },
                errors: {},

                get canModify() {
                    // Can save if creating a new event OR editing an existing one with edit perm.
                    // Public holidays are always read-only regardless of perms.
                    if (this.modal.isHoliday) return false;
                    return this.modal.id ? this.canEdit : this.canCreate;
                },

                init() {
                    this.calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
                        initialView: 'dayGridMonth',
                        firstDay: 1, // Monday
                        height: 'auto',
                        showNonCurrentDates: false, // hide leading/trailing days from adjacent months
                        fixedWeekCount: false,      // grid shrinks to only the weeks the current month needs
                        headerToolbar: false,       // we render our own header above the grid
                        displayEventTime: false,    // all events are date-only, so hide the "all-day" label in list view
                        views: {
                            listUpcoming: {
                                type: 'list',
                                buttonText: 'Upcoming',
                                listDayFormat: { weekday: 'long', month: 'long', day: 'numeric' },
                                noEventsText: 'No upcoming events.',
                                // Hard-anchor to today. Using `duration` alone causes FullCalendar
                                // to snap the start to Jan 1 of the current year (for year-length
                                // durations), which would leak past events.
                                visibleRange: function () {
                                    const start = new Date();
                                    start.setHours(0, 0, 0, 0);
                                    const end = new Date(start);
                                    end.setFullYear(end.getFullYear() + 5);
                                    return { start: start, end: end };
                                },
                            },
                        },
                        events: this.feedUrl,
                        dateClick: (info) => {
                            if (! this.canCreate) return;
                            this.openCreate(info.dateStr);
                        },
                        eventClick: (info) => {
                            info.jsEvent.preventDefault();
                            this.openView(info.event);
                        },
                        datesSet: (arg) => {
                            // Sync our custom header labels whenever the view changes.
                            this.view = arg.view.type;
                            const fmt = { month: 'long', year: 'numeric' };
                            const current = arg.view.currentStart;
                            const prev = new Date(current.getFullYear(), current.getMonth() - 1, 1);
                            const next = new Date(current.getFullYear(), current.getMonth() + 1, 1);
                            this.currentMonthName = current.toLocaleDateString('en-US', fmt);
                            this.prevMonthName = prev.toLocaleDateString('en-US', fmt);
                            this.nextMonthName = next.toLocaleDateString('en-US', fmt);
                        },
                    });
                    this.calendar.render();
                },

                setView(name) {
                    this.calendar.changeView(name);
                    this.view = name;
                },

                initDatePicker() {
                    if (this.fp) return;
                    this.fp = flatpickr(this.$refs.dateInput, {
                        dateFormat: 'Y-m-d',
                        allowInput: false,
                        disableMobile: true,
                        onChange: (dates, str) => { this.modal.date = str; },
                    });
                },

                openCreate(dateStr) {
                    this.modal = { open: true, id: null, title: '', date: dateStr, color: this.defaultColor, isHoliday: false };
                    this.errors = {};
                    this.$nextTick(() => { this.initDatePicker(); this.fp?.setDate(dateStr, false); });
                },

                openView(fcEvent) {
                    const date = fcEvent.startStr.substring(0, 10);
                    const isHoliday = !! fcEvent.extendedProps?.isHoliday;
                    // extendedProps.color carries the slug the server sent; fall back
                    // to defaultColor if somehow missing.
                    const color = fcEvent.extendedProps?.color || this.defaultColor;
                    this.modal = { open: true, id: fcEvent.id, title: fcEvent.title, date, color, isHoliday };
                    this.errors = {};
                    // Holiday view has no editable inputs, so no need to init flatpickr.
                    if (! isHoliday) {
                        this.$nextTick(() => { this.initDatePicker(); this.fp?.setDate(date, false); });
                    }
                },

                closeModal() {
                    this.modal.open = false;
                },

                async saveEvent() {
                    this.errors = {};
                    const isNew = ! this.modal.id;
                    const url = isNew
                        ? this.storeUrl
                        : '/calendar/events/' + this.modal.id;
                    const method = isNew ? 'POST' : 'PATCH';

                    try {
                        const res = await fetch(url, {
                            method,
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.csrf,
                            },
                            body: JSON.stringify({
                                title: this.modal.title,
                                date: this.modal.date,
                                color: this.modal.color,
                            }),
                        });

                        if (res.status === 422) {
                            const data = await res.json();
                            for (const [field, msgs] of Object.entries(data.errors || {})) {
                                this.errors[field] = Array.isArray(msgs) ? msgs[0] : msgs;
                            }
                            return;
                        }

                        if (! res.ok) throw new Error('Save failed (' + res.status + ')');

                        this.closeModal();
                        this.calendar.refetchEvents();
                    } catch (e) {
                        alert('Could not save event: ' + e.message);
                    }
                },

                async deleteEvent() {
                    if (! confirm('Delete this event?')) return;
                    try {
                        const res = await fetch('/calendar/events/' + this.modal.id, {
                            method: 'DELETE',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.csrf,
                            },
                        });
                        if (! res.ok) throw new Error('Delete failed (' + res.status + ')');

                        this.closeModal();
                        this.calendar.refetchEvents();
                    } catch (e) {
                        alert('Could not delete: ' + e.message);
                    }
                },
            };
        }
    </script>
@endpush
