import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'

export default defineConfig({
    plugins: [react()],
    base: '/martis/',
    build: {
        outDir: 'public',
        manifest: 'manifest.json',
        rollupOptions: {
            input: 'resources/js/app.tsx',
        },
    },
    server: {
        base: '/martis/',
        cors: true,
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    test: {
        globals: true,
        environment: 'jsdom',
    },
})
