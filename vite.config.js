import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/scss/app.scss', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Source Sans 3', {
                    weights: [300, 400, 600, 700],
                }),
            ],
        }),
    ],
    css: {
        preprocessorOptions: {
            scss: {
                // AdminLTE ve Bootstrap kaynakları "bootstrap/scss/..." yoluyla birbirini yükler.
                loadPaths: ['node_modules'],
                // Bootstrap 5.3 kaynakları yeni Sass sürümlerinde uyarı üretir; bizim kodumuzla ilgili değildir.
                quietDeps: true,
                silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function'],
            },
        },
    },
    server: {
        // Docker içindeki vite servisi için: dışarıdan erişilebilir, tarayıcı localhost:5173'e bağlanır.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
});
