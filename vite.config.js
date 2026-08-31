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
                // Same reasoning: the date picker is admin/teacher only, so
                // the pages that need it pull this in rather than every page
                // carrying it. Replaces the jsdelivr copy.
                'resources/js/flatpickr.js',
            ],
            refresh: true,
        }),
    ],
});
