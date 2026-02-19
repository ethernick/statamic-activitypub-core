import { defineConfig } from 'vite';
import vue2 from '@vitejs/plugin-vue2';
import vue3 from '@vitejs/plugin-vue';
import { resolve } from 'path';

// Detect Vue version from environment variable (defaults to Vue 2 for backwards compatibility)
const vueVersion = process.env.VUE_VERSION || '2';
const isVue2 = vueVersion === '2';
const distSubdir = isVue2 ? 'v5' : 'v6';

// Custom plugin to resolve version-specific component overrides
const versionResolverPlugin = () => ({
    name: 'version-resolver',
    enforce: 'pre',
    async resolveId(source, importer) {
        if (source.endsWith('.vue') && importer) {
            // Determine the version-specific extension to look for
            const versionExt = isVue2 ? '.v5.vue' : '.v6.vue';
            const versionedPath = source.replace(/\.vue$/, versionExt);

            // Attempt to resolve the versioned file
            const resolution = await this.resolve(versionedPath, importer, { skipSelf: true });

            if (resolution) {
                return resolution;
            }
        }
        return null; // Fallback to default resolution
    }
});

export default defineConfig({
    plugins: [
        versionResolverPlugin(),

        // Use appropriate Vue plugin based on version
        isVue2 ? vue2() : vue3(),
    ],
    build: {
        outDir: `dist/${distSubdir}`,
        emptyOutDir: true,
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
                // IIFE format ensures compatibility with standard <script> tags
                format: 'iife',
                // Map 'vue' imports to the global window.Vue provided by Statamic
                globals: {
                    vue: 'Vue',
                },
            },
            // Externalize Vue — the host (Statamic) provides it on window.Vue
            // v5 = Vue 2.7, v6 = Vue 3.5
            external: ['vue'],
        },
    },
    resolve: {
        alias: {},
    },
});
