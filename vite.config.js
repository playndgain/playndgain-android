import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css', // landing + docs
                'resources/js/site.js',  // Alpine + collapse, nothing else
                'resources/js/wall.js',  // offerwall — Alpine + motion/mini, its own budget
                'resources/css/filament/theme.css', // admin + publisher panels
                'resources/js/auth.js',  // the panels' auth pages — intro + wizard direction
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    // Keep prior hashed files so a stale browser tab after rebuild does not 404
    // the CSS/JS it still has in memory. Production deploys wipe the dir anyway.
    build: {
        emptyOutDir: false,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
