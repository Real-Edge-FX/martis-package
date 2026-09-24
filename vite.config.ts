import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import fs from 'fs'
import path from 'path'

// Read the package version once and expose it to the bundle via Vite's
// `define` mechanism so `window.Martis.version` reflects the running
// package release at runtime. Falls back to "dev" when package.json is
// missing or unreadable (CI / test scenarios).
function readPackageVersion(): string {
    try {
        const pkg = JSON.parse(fs.readFileSync(path.join(__dirname, 'package.json'), 'utf8')) as {version?: string}
        return pkg.version ?? 'dev'
    } catch {
        return 'dev'
    }
}

export default defineConfig({
    plugins: [react()],
    base: '/vendor/martis/',
    publicDir: false,
    define: {
        __MARTIS_VERSION__: JSON.stringify(readPackageVersion()),
    },
    build: {
        outDir: 'public',
        manifest: 'manifest.json',
        // Empty `public/` before each build so old hashed assets don't
        // accumulate in the committed build (we've seen 1k+ stale chunks
        // after a few weeks of active development, and consumers publish
        // this directory with `martis:publish-assets`). Vite already
        // empties an outDir inside the project root by default; the flag
        // is explicit so the behaviour never depends on that default.
        // The whole directory only contains build artifacts
        // (manifest.json + assets/), so emptying it is safe.
        emptyOutDir: true,
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

                    // Phosphor icons — eagerly-imported set folds into
                    // the importing chunk (main app); the
                    // `import.meta.glob` fallback under `dist/csr/*`
                    // emits one chunk per icon. The rule explicitly
                    // skips Phosphor so the React rule below doesn't
                    // sweep up `IconBase` and force the entire
                    // 1500-icon set into `react-vendor`.
                    if (id.includes('@phosphor-icons/react')) {
                        return undefined
                    }

                    // Match ONLY the React core packages, not anything
                    // that happens to contain the word `react`. The
                    // looser rule used to swallow Phosphor and Tabler
                    // and triggered a 5 MB react-vendor chunk.
                    if (
                        id.includes('/node_modules/react/') ||
                        id.includes('/node_modules/react-dom/') ||
                        id.includes('/node_modules/react-router-dom/') ||
                        id.includes('/node_modules/scheduler/')
                    ) {
                        return 'react-vendor'
                    }

                    if (
                        id.includes('@tanstack/react-query') ||
                        id.includes('/node_modules/i18next') ||
                        id.includes('react-i18next')
                    ) {
                        return 'app-vendor'
                    }

                    if (id.includes('/node_modules/trix') || id.includes('/node_modules/marked')) {
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
        },
    },
    test: {
        // Only the package's own tests. Vitest's default include globs the
        // whole root with dotfiles allowed, so it also collected the copies
        // in git worktrees under `.claude/worktrees/` (doubling the count and
        // mixing source trees through the `@` alias).
        include: ['resources/js/**/*.test.{ts,tsx}'],
        setupFiles: ['resources/js/test-setup.ts'],
        globals: true,
        environment: 'jsdom',
        // The first test of a file that renders fields pays for transforming
        // their lazy chunks, which a loaded machine (a CI runner, a full
        // parallel run) can stretch past the 5 s default. The async
        // utilities give up at 3 s (test-setup.ts), well inside this.
        testTimeout: 10_000,
    },
})
