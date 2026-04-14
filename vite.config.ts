import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'

export default defineConfig({
    plugins: [react()],
    base: '/vendor/martis/',
    publicDir: false,
    build: {
        outDir: 'public',
        manifest: 'manifest.json',
        chunkSizeWarningLimit: 1000,
        rollupOptions: {
            input: 'resources/js/app.tsx',
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) return

                    if (id.includes('@codemirror') || id.includes('@uiw/react-codemirror')) {
                        return 'codemirror'
                    }

                    if (id.includes('primereact') || id.includes('primeicons')) {
                        return 'primereact'
                    }

                    if (id.includes('react') || id.includes('react-dom') || id.includes('react-router-dom')) {
                        return 'react-vendor'
                    }

                    if (id.includes('@tanstack/react-query') || id.includes('i18next') || id.includes('react-i18next')) {
                        return 'app-vendor'
                    }

                    if (id.includes('trix') || id.includes('marked')) {
                        return 'richtext'
                    }
                },
            },
        },
    },
    server: {
        base: '/vendor/martis/',
        cors: true,
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
            '@images': path.resolve(__dirname, './resources/images'),
            '@user': path.resolve(__dirname, './resources/js/user'),
        },
    },
    test: {
        setupFiles: ['resources/js/test-setup.ts'],
        globals: true,
        environment: 'jsdom',
    },
})