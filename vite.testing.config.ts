import { defineConfig, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'
import fs from 'fs'
import path from 'path'

/**
 * The test runtime of consumer extensions (`npm run build:testing`, see
 * scripts/build-testing-kit.mjs and docs/testing-extensions.md).
 *
 * - **What it builds:** the Martis runtime as an ES library, in development
 *   mode, for a consumer's Vitest or Playwright run.
 * - **External:** React, ReactDOM and Phosphor are the consumer's own
 *   (`martis:install` adds them), so the extension, the runtime and Testing
 *   Library share one copy, a development build that supports `act()`.
 * - **Bundled:** React Router, i18next, react-i18next and React Query. The
 *   published shims serve them from `window.Martis.runtime`, which this
 *   runtime fills.
 */

const EXTERNAL = /^(?:react|react-dom|@phosphor-icons\/react)(?:\/|$)/

/**
 * The React the panel runs: the package's own, which `public/` bundles. The
 * runtime fails a consumer's test run on another major (testing/reactMajor.ts).
 */
function readPanelReactVersion(): string {
    const file = path.join(__dirname, 'node_modules/react/package.json')
    const version = (JSON.parse(fs.readFileSync(file, 'utf8')) as {version?: unknown}).version
    if (typeof version !== 'string') {
        throw new Error(`vite.testing.config.ts: ${file} has no version. Run npm ci in the martis package.`)
    }

    return version
}

function readPackageVersion(): string {
    try {
        const pkg = JSON.parse(fs.readFileSync(path.join(__dirname, 'package.json'), 'utf8')) as {version?: string}
        return pkg.version ?? 'dev'
    } catch {
        return 'dev'
    }
}

/**
 * The icon registry's lazy glob names each Phosphor icon by its file path
 * (`/node_modules/@phosphor-icons/react/dist/csr/X.es.js`). In a library
 * build that becomes a path relative to this package, which a consumer
 * cannot resolve. Point each one at the package's exported subpath
 * (`./dist/csr/*` maps `X` to `X.es.js`), resolved from the consumer's
 * node_modules. The id must not also match `external` above, or Rollup keeps
 * the relative path.
 */
function phosphorBareSpecifiers(): Plugin {
    return {
        name: 'martis-phosphor-bare-specifiers',
        enforce: 'pre',
        resolveId(source) {
            const match = source.match(/node_modules\/(@phosphor-icons\/react\/dist\/csr\/[^/]+)\.es\.js$/)
            return match ? { id: match[1], external: true } : null
        },
    }
}

export default defineConfig({
    plugins: [react(), phosphorBareSpecifiers()],
    mode: 'development',
    publicDir: false,
    define: {
        __MARTIS_VERSION__: JSON.stringify(readPackageVersion()),
        __MARTIS_REACT_VERSION__: JSON.stringify(readPanelReactVersion()),
        // Bundled libraries take their development branches; the library
        // build would otherwise leave process.env for the consumer, and a
        // Playwright page has no `process`.
        'process.env.NODE_ENV': JSON.stringify('development'),
    },
    build: {
        outDir: 'dist/testing/runtime',
        emptyOutDir: true,
        minify: false,
        sourcemap: false,
        cssCodeSplit: false,
        lib: {
            entry: 'resources/js/testing/index.tsx',
            formats: ['es'],
            fileName: () => 'index.mjs',
            cssFileName: 'martis',
        },
        rollupOptions: {
            external: (id) => EXTERNAL.test(id),
            output: { chunkFileNames: 'chunks/[name]-[hash].mjs' },
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
            '@images': path.resolve(__dirname, './resources/images'),
        },
    },
})
