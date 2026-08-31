/*
 * Quill on its own Vite entry — the rich-text editor for material bodies and
 * assignment descriptions.
 *
 * Replaces the jsdelivr copy. Same version (2.0.2), so this is a delivery
 * change, not an upgrade: the CDN tag was pinned and so is package.json.
 *
 * window.Quill is set because every caller is inline — `new Quill(container,
 * ...)` inside @push('scripts') and in the fetched modal fragments, neither
 * of which can import a module.
 *
 * The snow theme's CSS is imported here so it travels with the entry. That
 * puts it wherever @vite sits in the document, which must stay ABOVE the
 * hand-written .ql-* overrides in the views — those rely on coming later to
 * win the cascade at equal specificity.
 */
import Quill from 'quill';
import 'quill/dist/quill.snow.css';

window.Quill = Quill;
