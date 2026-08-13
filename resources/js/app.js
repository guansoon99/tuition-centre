import Alpine from 'alpinejs';

/*
 * Alpine was previously pulled from unpkg as `alpinejs@3.x.x` — an unpinned
 * range, so the version could change under us without warning. It's now a
 * real dependency, pinned in package.json and bundled here.
 *
 * window.Alpine stays exposed: Alpine's own devtools expect it, and some
 * inline x-init handlers reference it.
 */
window.Alpine = Alpine;

/*
 * Start Alpine on DOMContentLoaded rather than immediately.
 *
 * Module scripts are deferred, so this file executes while readyState is
 * already 'interactive' — Alpine.start() would then walk the DOM straight
 * away, before any page-specific entry point further down the document has
 * run. The calendar page relies on resources/js/calendar.js having set
 * window.FullCalendar by the time its x-data init() fires, and it was
 * throwing "FullCalendar is not defined" for exactly this reason.
 *
 * Deferred scripts all execute before DOMContentLoaded, so waiting for it
 * guarantees every entry point has published its globals first. Costs
 * nothing: the document is fully parsed either way.
 */
// 'interactive' means parsing has finished but DOMContentLoaded has NOT
// fired yet — which is exactly the state a deferred module runs in, and the
// spec guarantees every remaining deferred script executes before that event.
// So both 'loading' and 'interactive' wait for it; only an already-'complete'
// document (a late dynamic import) starts immediately.
if (document.readyState === 'complete') {
    Alpine.start();
} else {
    document.addEventListener('DOMContentLoaded', () => Alpine.start());
}
