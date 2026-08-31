import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css', // landing + docs
                'resources/js/site.js',  // Alpine + GSAP
                'resources/js/wall.js',  // offerwall — Alpine only, no GSAP
                'resources/css/filament/theme.css', // admin + publisher panels
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
