import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import fs from 'fs'
import path from 'path'

/**
 * Resolve the directory backing the `@user` alias.
 *
 * Priority (highest first):
 *   1. `MARTIS_USER_DIR` env var — explicit override.
 *   2. Auto-discovery: walk up from the package's own directory looking
 *      for a Laravel app root that ships a Martis extensions folder
 *      (`resources/martis-extensions/` by convention). When the package
 *      lives inside `vendor/martis/martis/` of a Laravel app the walk
 *      finds the consumer's extension dir without any env var.
 *   3. Fallback to the package's own empty `resources/js/user/` so the
 *      package itself can build standalone (no consumer involved).
 *
 * The auto-discovery step is a pure best-effort — if no Laravel root is
 * found the fallback keeps the previous behaviour intact, so consumers
 * that already set `MARTIS_USER_DIR` are unaffected.
 */
function resolveUserDir(packageRoot: string): string {
    if (process.env.MARTIS_USER_DIR) {
        return path.resolve(process.env.MARTIS_USER_DIR)
    }

    // Walk up from the package root looking for a Laravel app marker
    // (`artisan` file at the root). Cap the walk at 8 levels so a stray
    // run from `/` doesn't traverse the entire filesystem.
    let dir = packageRoot
    for (let depth = 0; depth < 8; depth++) {
        const parent = path.dirname(dir)
        if (parent === dir) break // reached filesystem root

        const artisan = path.join(parent, 'artisan')
        if (fs.existsSync(artisan)) {
            const ext = path.join(parent, 'resources/martis-extensions')
            if (fs.existsSync(ext)) {
                return ext
            }
            // Found a Laravel root but no extensions dir — stop walking
            // so we don't accidentally pick up an unrelated app further
            // up the tree. Fall through to the package fallback.
            break
        }
        dir = parent
    }

    return path.join(packageRoot, 'resources/js/user')
}

export default defineConfig({
    plugins: [react()],
    base: '/vendor/martis/',
    publicDir: false,
    build: {
        outDir: 'public',
        manifest: 'manifest.json',
        rollupOptions: {
            input: 'resources/js/app.tsx',
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) return

                    if (id.includes('@codemirror') || id.includes('@uiw/react-codemirror')) {
                        return 'codemirror'
                    }

                    if (id.includes('@phosphor-icons/react')) {
                        return 'phosphor-icons'
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
            '@user': resolveUserDir(__dirname),
        },
    },
    test: {
        setupFiles: ['resources/js/test-setup.ts'],
        globals: true,
        environment: 'jsdom',
    },
})
