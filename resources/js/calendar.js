/*
 * Calendar page bundle — FullCalendar + flatpickr.
 *
 * Loaded ONLY on /calendar, via its own Vite entry point. These must not go
 * into app.js: that ships on every page, and FullCalendar alone is larger
 * than the entire rest of the app bundle.
 *
 * Replaces the CDN build (index.global.min.js), which contains every
 * FullCalendar plugin. This page uses three of them, so importing explicitly
 * drops roughly half the weight. That matters because /calendar is NOT
 * admin-only — it has no permission gate and sits in every student's sidebar.
 */

import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import flatpickr from 'flatpickr';

import 'flatpickr/dist/flatpickr.min.css';

/*
 * The global CDN build auto-registers its plugins, so the inline script in
 * admin/calendar/index.blade.php calls `new FullCalendar.Calendar(el, opts)`
 * with no `plugins` key. Modular imports don't auto-register, so this shim
 * mirrors that API and injects the three plugins the page actually uses:
 *
 *   dayGrid      initialView: 'dayGridMonth'
 *   list         the custom `listUpcoming` view (type: 'list')
 *   interaction  dateClick
 *
 * Keeping the shim means the Blade needs no changes — worth it, since that
 * view is 550+ lines of calendar wiring.
 *
 * An explicit `plugins` option in the call still wins, via the spread.
 */
class ShimmedCalendar extends Calendar {
    constructor(el, options = {}) {
        super(el, {
            plugins: [dayGridPlugin, listPlugin, interactionPlugin],
            ...options,
        });
    }
}

window.FullCalendar = { Calendar: ShimmedCalendar };
window.flatpickr = flatpickr;
