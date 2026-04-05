import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'

export default defineConfig({
    plugins: [react()],
    base: '/vendor/martis/',
    build: {
        outDir: 'public',
        manifest: 'manifest.json',
        rollupOptions: {
            input: 'resources/js/app.tsx',
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
            '@user': path.resolve(__dirname, '../../playground/resources/js'),
        },
    },
    test: {
        setupFiles: ['resources/js/test-setup.ts'],
        globals: true,
        environment: 'jsdom',
    },
})
