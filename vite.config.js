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
            ],
            refresh: true,
        }),
    ],
});
