/*
 * Tom Select on its own Vite entry — the searchable pickers for assigning
 * teachers and students to a course, and the announcement audience.
 *
 * Replaces the jsdelivr copy at the same pinned version (2.3.1).
 *
 * The `complete` build, matching the tom-select.complete.min.js the CDN tag
 * loaded. Callers currently pass no plugins — `new TomSelect(el, { create:
 * false, allowEmptyOption: true })` — so the base build would do, but
 * swapping bundles is a behaviour change that has nothing to do with moving
 * off a CDN, and a later caller reaching for a plugin would fail oddly.
 *
 * Likewise tom-select.css is the default theme the CDN served, not the
 * bootstrap variants sitting beside it. The .ts-* overrides in the views
 * assume it, and must keep loading after this.
 */
import TomSelect from 'tom-select/dist/js/tom-select.complete.js';
import 'tom-select/dist/css/tom-select.css';

window.TomSelect = TomSelect;
