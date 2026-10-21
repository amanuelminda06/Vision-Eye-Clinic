import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/**
 * The front-end build.
 *
 * The compiled assets are COMMITTED under public/build/ — the Plesk host runs
 * no Node.js. Rebuild them with:
 *
 *     npm install && npm run build
 *
 * and commit the result (public/build/manifest.json plus public/build/assets/).
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        // A stable, readable file name per entry — the manifest is what the
        // Blade templates resolve, so the names do not have to be hashed.
        rollupOptions: {
            output: {
                entryFileNames: 'assets/[name]-[hash].js',
                chunkFileNames: 'assets/[name]-[hash].js',
                assetFileNames: 'assets/[name]-[hash][extname]',
            },
        },
    },
});
