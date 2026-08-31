import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Separate entry so FullCalendar loads only on /calendar —
                // folding it into app.js would make every page pay for it.
                'resources/js/calendar.js',
                // Same reasoning for the rest: all admin/teacher only, so the
                // pages that need them pull them in rather than every page
                // carrying the weight. Each replaces a jsdelivr copy that was
                // loading without an integrity hash.
                'resources/js/flatpickr.js',
                'resources/js/quill.js',
                'resources/js/tom-select.js',
                'resources/js/sortable.js',
            ],
            refresh: true,
        }),
    ],
});
