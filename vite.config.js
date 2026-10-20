import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: {
                paths: [
                    'app/**',
                    'bootstrap/**',
                    'config/**',
                    'public/**',
                    'resources/**',
                    'routes/**',
                ],
                config: { delay: 100 },
            },
            fonts: [
                bunny('Space Grotesk', {
                    weights: [400, 500, 600, 700],
                }),
                bunny('Inter', {
                    weights: [400, 500, 600, 700, 800, 900],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: [
                '**/database/**',
                '**/storage/framework/**',
                '**/storage/logs/**',
            ],
        },
    },
});
