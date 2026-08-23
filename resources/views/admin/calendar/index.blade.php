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
                        <label class="mb-1 block text-xs font-medium text-slate-700">Type</label>
                        <div class="flex flex-wrap gap-2">
                            {{-- Radio pills same pattern as course material types. --}}
                            @foreach (['pill' => 'Bar', 'background' => 'Highlight'] as $val => $lbl)
                                <label class="inline-flex cursor-pointer items-center rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-900 has-[:checked]:text-white"
                                       {{-- Highlight is never disabled: on a day
                                            that already has one, picking it loads
                                            that event for editing rather than
                                            making a second. --}}
                                       :class="canModify ? '' : 'opacity-60 cursor-not-allowed pointer-events-none'">
                                    <input type="radio" x-model="modal.displayStyle" value="{{ $val }}" class="sr-only">
                                    {{ $lbl }}
                                </label>
                            @endforeach
                        </div>
                        <p x-show="errors.display_style" x-text="errors.display_style" class="mt-1 text-xs text-red-600"></p>
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
    {{-- FullCalendar and flatpickr styles now ship inside the calendar bundle
         (see resources/js/calendar.js) rather than coming from a CDN. --}}
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
        /* Public holiday cells (background events with our light-red tint):
           strengthen the color and make the date number red so the day
           reads as a holiday at a glance, matching printed MY calendars. */
        .fc .fc-daygrid-day.fc-day-has-holiday {
            background-color: #fef2f2;                    /* rose-50 wash */
        }
        /* A marked day — holiday or user highlight — reads with a heavier date
           number. The weight lives here because it is the same for both; the
           colour does not, because a user highlight's is chosen per event and
           no stylesheet can know it. eventDidMount paints it inline from the
           event's own palette entry.

           This rule used to hardcode red-600 and apply to holidays only, which
           caused both bugs at once: user highlights got no weight, and an auto
           holiday (red-600, #dc2626) sat next to a hand-made red highlight
           (COLOR_TEXTS['red'], #b91c1c) in two visibly different reds. */
        .fc .fc-daygrid-day.fc-day-has-holiday .fc-daygrid-day-number,
        .fc .fc-daygrid-day.fc-day-has-user-bg .fc-daygrid-day-number {
            font-weight: 600;
        }
        /* Hide the raw background-event pill since we've styled the cell
           itself — the cell tint + red date number + injected label carry
           the message. */
        .fc .fc-bg-event {
            opacity: 0;
        }
        /* Holiday name written directly into the day cell (injected via
           eventDidMount). Sits below the date number, wraps if long. */
        .fc .fc-holiday-label,
        .fc .fc-user-bg-label {
            padding: 2px 4px;
            font-size: 0.7rem;
            font-weight: 500;
            line-height: 1.2;
            word-break: break-word;
        }
        .fc .fc-holiday-label {
            color: rgb(185 28 28);                        /* red-700 */
        }
        /* User background events set their cell background + text color
           inline based on the chosen palette slug. Cursor hints it's clickable —
           so only emit it for someone the click will actually do something for.
           Without this a student gets a pointer over a cell that ignores them. */
        @if ($canCreate || $canEdit || $canDelete)
            .fc .fc-day-has-user-bg { cursor: pointer; }
        @endif
    </style>
@endpush

@push('scripts')
    {{-- Calendar-only bundle: FullCalendar (dayGrid + list + interaction) and
         flatpickr. Exposes window.FullCalendar / window.flatpickr so the
         inline code below is unchanged. Loaded only on this page. --}}
    @vite('resources/js/calendar.js')
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
                modal: { open: false, id: null, title: '', date: '', color: opts.defaultColor, displayStyle: 'pill', isHoliday: false },
                errors: {},

                get canModify() {
                    // Can save if creating a new event OR editing an existing one with edit perm.
                    // Public holidays are always read-only regardless of perms.
                    if (this.modal.isHoliday) return false;
                    return this.modal.id ? this.canEdit : this.canCreate;
                },

                /** The day's single highlight, if it has one. Holidays excluded. */
                userHighlightOn(dateStr) {
                    if (! this.calendar || ! dateStr) return null;

                    return this.calendar.getEvents().find(e =>
                        e.startStr === dateStr
                        && e.display === 'background'
                        && ! e.extendedProps?.isHoliday
                    ) || null;
                },

                /**
                 * Type doubles as a mode switch on a day that already has a
                 * highlight.
                 *
                 * A day holds only one highlight, so choosing Highlight there
                 * cannot mean "make another" — it means "edit that one", and
                 * the existing title and colour load in. Choosing Bar again
                 * means "create a bar here instead", so the form empties back
                 * out rather than converting the highlight into a bar.
                 */
                watchTypeSwitch() {
                    this.$watch('modal.displayStyle', (style, previous) => {
                        if (! this.modal.open || style === previous) return;

                        const existing = this.userHighlightOn(this.modal.date);
                        if (! existing) return;

                        const isLoaded = String(existing.id) === String(this.modal.id ?? '');

                        if (style === 'background' && ! isLoaded) {
                            this.modal.id = existing.id;
                            this.modal.title = existing.title;
                            this.modal.color = existing.extendedProps?.color || this.defaultColor;
                            this.errors = {};
                            return;
                        }

                        if (style === 'pill' && isLoaded) {
                            this.modal.id = null;
                            this.modal.title = '';
                            this.modal.color = this.defaultColor;
                            this.errors = {};
                        }
                    });
                },

                get canOpenModal() {
                    // The calendar is readable by everyone, but the modal is an
                    // editing surface. Opened by someone with no write permission
                    // it is a dead end — every field disabled and nothing to do
                    // but close it — so it should not open at all.
                    //
                    // Delete counts as a write: a delete-only role still needs a
                    // way to reach that button. A student has none of the three,
                    // which is the case this exists for.
                    return this.canCreate || this.canEdit || this.canDelete;
                },

                init() {
                    this.watchTypeSwitch();

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
                            // Background events (holidays + user highlights) don't
                            // fire eventClick, so a click on a tinted cell lands
                            // here instead.
                            const bgEvents = this.calendar.getEvents().filter(e =>
                                e.startStr === info.dateStr && e.display === 'background'
                            );

                            // A day holds at most one highlight, so if there is
                            // one here the click can only mean that event —
                            // open it for editing. Adding a bar to the same day
                            // is still one step away: switching Type to Bar in
                            // the modal turns it into a new-bar form (see the
                            // displayStyle watcher in init).
                            //
                            // Background events never fire eventClick of their
                            // own, so this cell click is the only way in.
                            const userBg = bgEvents.find(e => ! e.extendedProps?.isHoliday);
                            if (userBg) {
                                this.openView(userBg);
                                return;
                            }

                            // Nothing of ours on this day. Holidays don't take
                            // the click — they are read-only and reserve
                            // nothing — so fall through to create.
                            if (this.canCreate) {
                                this.openCreate(info.dateStr);
                                return;
                            }

                            // Can't create: the holiday is all there is to show.
                            if (bgEvents.length) {
                                this.openView(bgEvents[0]);
                            }
                        },
                        eventClick: (info) => {
                            info.jsEvent.preventDefault();
                            this.openView(info.event);
                        },
                        // When a holiday background event mounts, tag the
                        // corresponding day cell and write the holiday name
                        // directly into the cell as a small red text label
                        // (below the date number). No pill, no click target —
                        // reads like a printed Malaysian calendar.
                        //
                        // Two gotchas:
                        //   - eventDidMount fires more than once per event as
                        //     FullCalendar re-renders → dedupe by title.
                        //   - showNonCurrentDates:false hides the date number
                        //     on leading/trailing cells but keeps them in the
                        //     DOM with `data-date`. Filter those out with
                        //     :not(.fc-day-other) so the label doesn't land on
                        //     a numberless neighbouring-month cell.
                        eventDidMount: (info) => {
                            // Handle two flavours of background events:
                            //   - Auto holidays (red, read-only, .fc-holiday-label)
                            //   - User events with display_style='background'
                            //     (chosen color, clickable, .fc-user-bg-label)
                            const isHoliday = info.event.extendedProps?.isHoliday;
                            const isUserBg = ! isHoliday
                                && info.event.display === 'background';
                            if (! isHoliday && ! isUserBg) return;

                            // Find the "real" cell for this date — belongs to the
                            // current view, not a hidden neighbour-month cell.
                            const candidates = document.querySelectorAll(
                                '.fc-daygrid-day[data-date="' + info.event.startStr + '"]'
                            );
                            const cell = Array.from(candidates).find(c => {
                                if (c.classList.contains('fc-day-disabled')) return false;
                                if (c.classList.contains('fc-day-other')) return false;
                                const num = c.querySelector('.fc-daygrid-day-number');
                                return num && num.textContent.trim() !== '';
                            });
                            if (! cell) return;

                            const labelClass = isHoliday ? 'fc-holiday-label' : 'fc-user-bg-label';
                            const cellClass = isHoliday ? 'fc-day-has-holiday' : 'fc-day-has-user-bg';

                            cell.classList.add(cellClass);
                            // Both types now carry their tint + text color inline
                            // from the feed — a user red+highlight event renders
                            // identically to an auto-fetched public holiday.
                            cell.style.setProperty('background-color', info.event.backgroundColor);

                            // Tint the date number to match the event. Both kinds
                            // go through here, from the same field, so a holiday
                            // and a hand-made red highlight land on exactly the
                            // same red — the feed sends COLOR_TEXTS['red'] for
                            // holidays too. Inline because a user's colour is
                            // per-event, and it also beats the Sunday rose rule
                            // that would otherwise win on Sundays.
                            const dayNumber = cell.querySelector('.fc-daygrid-day-number');
                            if (dayNumber) {
                                dayNumber.style.setProperty('color', info.event.extendedProps.textHex);
                            }

                            let label = cell.querySelector('.' + labelClass);
                            if (! label) {
                                label = document.createElement('div');
                                label.className = labelClass;
                                label.style.color = info.event.extendedProps.textHex;

                                const frame = cell.querySelector('.fc-daygrid-day-frame');
                                (frame || cell).appendChild(label);
                            }
                            // Dedupe: don't append a title that's already in the label.
                            const parts = label.textContent
                                ? label.textContent.split(' • ')
                                : [];
                            if (parts.includes(info.event.title)) return;
                            parts.push(info.event.title);
                            label.textContent = parts.join(' • ');

                            // Tooltip aggregates ALL names on this cell (either type).
                            const existingTitle = cell.getAttribute('title');
                            const titleParts = existingTitle ? existingTitle.split(', ') : [];
                            if (! titleParts.includes(info.event.title)) {
                                titleParts.push(info.event.title);
                                cell.setAttribute('title', titleParts.join(', '));
                            }
                        },
                        // Fires on every event refetch (save/delete → refetchEvents()
                        // triggers loading:true → false cycle). We wipe injected
                        // markup on the LOAD-START edge so cells are clean before
                        // eventDidMount rebuilds them for the fresh event set.
                        // Without this, deleting a highlight event leaves the
                        // tinted cell + label until a manual page refresh.
                        loading: (isLoading) => {
                            if (isLoading) this.wipeInjectedBgMarkup();
                        },
                        datesSet: (arg) => {
                            // Same wipe on view change — FullCalendar reuses day
                            // cell DOM elements across view switches so stale
                            // labels need explicit cleanup here too.
                            this.wipeInjectedBgMarkup();

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

                // Remove all previously-injected holiday / user-bg markup from
                // the day cells so eventDidMount can rebuild cleanly. Called
                // before every event re-render (loading) and every view change
                // (datesSet).
                wipeInjectedBgMarkup() {
                    document.querySelectorAll('.fc-holiday-label, .fc-user-bg-label').forEach(el => el.remove());
                    document.querySelectorAll('.fc-day-has-holiday, .fc-day-has-user-bg').forEach(cell => {
                        cell.classList.remove('fc-day-has-holiday', 'fc-day-has-user-bg');
                        cell.style.removeProperty('background-color');
                        cell.removeAttribute('title');
                        // The date number's colour is set inline for user
                        // highlights, so it has to be cleared here too —
                        // otherwise deleting a highlight leaves its colour on
                        // the number after the cell itself has gone plain.
                        cell.querySelector('.fc-daygrid-day-number')?.style.removeProperty('color');
                    });
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
                    if (! this.canCreate) return;
                    this.modal = { open: true, id: null, title: '', date: dateStr, color: this.defaultColor, displayStyle: 'pill', isHoliday: false };
                    this.errors = {};
                    this.$nextTick(() => { this.initDatePicker(); this.fp?.setDate(dateStr, false); });
                },

                openView(fcEvent) {
                    // Guarded here rather than only at the call sites, so the
                    // two entry points (dateClick's background branch and
                    // eventClick) cannot drift apart — and neither can a third.
                    if (! this.canOpenModal) return;
                    const date = fcEvent.startStr.substring(0, 10);
                    const isHoliday = !! fcEvent.extendedProps?.isHoliday;
                    // extendedProps carry the slug + style the server sent; fall back
                    // to defaults if somehow missing.
                    const color = fcEvent.extendedProps?.color || this.defaultColor;
                    const displayStyle = fcEvent.extendedProps?.displayStyle || 'pill';
                    this.modal = { open: true, id: fcEvent.id, title: fcEvent.title, date, color, displayStyle, isHoliday };
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
                                display_style: this.modal.displayStyle,
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
