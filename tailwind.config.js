/** @type {import('tailwindcss').Config} */
export default {
    // Every place a class name can appear. Tailwind only emits CSS for the
    // classes it finds here, so a path missing from this list means those
    // styles silently vanish in the built stylesheet.
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './app/**/*.php',
    ],

    theme: {
        extend: {
            colors: {
                // Overrides Tailwind's default red so every `red-*` class
                // resolves to the Bootstrap #dc3545 family. Ported verbatim
                // from the old inline CDN config in
                // resources/views/partials/tailwind-config.blade.php.
                red: {
                    50: '#fef2f4',
                    100: '#f8d7da',
                    200: '#f1aeb5',
                    300: '#ea868f',
                    400: '#e15b6c',
                    500: '#dc3545',
                    600: '#c82333',
                    700: '#b02a37',
                    800: '#842029',
                    900: '#58151c',
                    950: '#2c0608',
                },
            },
        },
    },

    plugins: [],
};
