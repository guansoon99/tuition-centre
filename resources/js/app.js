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

Alpine.start();
