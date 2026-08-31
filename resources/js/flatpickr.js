/*
 * flatpickr on its own Vite entry.
 *
 * Not in app.js: that ships on every page, and the date picker is only used
 * on admin and teacher screens — the same reasoning that keeps FullCalendar
 * in calendar.js. Pages that need it pull this in with
 * `@vite('resources/js/flatpickr.js')`.
 *
 * Replaces the jsdelivr copy the views used to load. flatpickr was already a
 * pinned dependency in package.json and installed on every `npm install`, so
 * the browser was fetching from a third party a file that was sitting in
 * node_modules the whole time — with no integrity hash, on pages where users
 * and roles are managed.
 *
 * window.flatpickr is set because callers are inline: `x-init="flatpickr(...)"`
 * in Blade and plain scripts in @push('scripts'), neither of which can import
 * a module. calendar.js does the same for the same reason.
 */
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';

window.flatpickr = flatpickr;
