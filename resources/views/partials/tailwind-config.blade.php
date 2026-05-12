{{-- Override Tailwind's red palette so all `red-*` classes resolve to the
     Bootstrap #dc3545 family. Loaded right after the Tailwind CDN script. --}}
<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    red: {
                        50:  '#fef2f4',
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
    };
</script>
