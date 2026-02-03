import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { viteExternalsPlugin } from 'vite-plugin-externals';
import { resolve } from 'path';

export default defineConfig({
    plugins: [
        vue(),
        viteExternalsPlugin({ vue: 'Vue' }),
    ],
    build: {
        outDir: 'dist',
        manifest: true,
        rollupOptions: {
            input: {
                cp: resolve(__dirname, 'resources/js/cp.js'),
            },
            output: {
                entryFileNames: 'js/[name].js',
                chunkFileNames: 'js/[name]-[hash].js',
                assetFileNames: (assetInfo) => {
                    if (assetInfo.name.endsWith('.css')) {
                        return 'css/cp.css';
                    }
                    return 'assets/[name]-[hash][extname]';
                },
            },
        },
    },
});
