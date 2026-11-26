import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/super-admin.css',
                'resources/js/app.js',
                'resources/css/catalog.css',
                'resources/js/catalog.js',
            ],
            refresh: true,
        }),
    ],
    server: {
        host: 'localhost',
    },
});
