/**
 * Consumer-extension bundle config for Martis (v1.10.0+).
 *
 * Built by `npm run build:extensions`. Output lands at
 * `public/vendor/martis-user/extensions.js`, which Martis loads at
 * runtime via the URL listed in `MARTIS_EXTENSIONS` (set in `.env`).
 *
 * The bundle's entry — `resources/js/martis-extensions/index.ts` —
 * auto-discovers every `.tsx` file under the four buckets
 * (`tools/`, `fields/`, `cards/`, `overrides/`) using
 * `import.meta.glob` and registers the components on
 * `window.Martis.componentRegistry`. You normally don't edit this
 * config; the `martis:tool|field|card|component` generators do all
 * the wiring.
 *
 * React + runtime handling (v1.10.0+):
 *
 * Eight bare module specifiers are aliased to small shim modules
 * under `resources/js/martis-extensions/.shims/` that re-export
 * from `window.Martis.{react, reactJsxRuntime, runtime}`. Vite
 * inlines those shims into the bundle at build time, so the compiled
 * `extensions.js` reads everything off the host SPA's React tree
 * instead of shipping a duplicate copy or trying to resolve bare
 * specifiers in the browser.
 *
 * | Bare specifier              | Shim file                              |
 * |-----------------------------|----------------------------------------|
 * | `react`, `react-dom`        | `.shims/react.mjs`                     |
 * | `react/jsx-runtime`         | `.shims/react-jsx-runtime.mjs`         |
 * | `react-router-dom`          | `.shims/react-router-dom.mjs`          |
 * | `react-i18next`             | `.shims/react-i18next.mjs`             |
 * | `@tanstack/react-query`     | `.shims/tanstack-react-query.mjs`      |
 * | `@martis/runtime`           | `.shims/runtime.mjs`                   |
 *
 * Plus three legacy-path aliases (also pointing at the runtime shim)
 * so override stubs published by older Martis versions keep building:
 * `@/contexts/*`, `@/lib/*`, `@/components/auth/*`, `@martis/martis/*`.
 *
 * The earlier v1.9.0–v1.9.2 stub used `rollupOptions.external` +
 * `output.globals` for the same goal, but `globals` only works for
 * UMD/IIFE; ES module output kept the bare imports, which the
 * browser refused with "Failed to resolve module specifier". v1.9.3
 * introduced the alias approach for React; v1.10.0 extends it to
 * the rest of the consumer-override import surface.
 */

import {defineConfig} from 'vite'
import react from '@vitejs/plugin-react'
import path from 'node:path'

const shimsDir = path.resolve(__dirname, 'resources/js/martis-extensions/.shims')

const reactShim = path.join(shimsDir, 'react.mjs')
const jsxRuntimeShim = path.join(shimsDir, 'react-jsx-runtime.mjs')
const routerShim = path.join(shimsDir, 'react-router-dom.mjs')
const i18nextShim = path.join(shimsDir, 'react-i18next.mjs')
const queryShim = path.join(shimsDir, 'tanstack-react-query.mjs')
const runtimeShim = path.join(shimsDir, 'runtime.mjs')

export default defineConfig({
  plugins: [react()],
  // outDir lives inside public/, so leaving publicDir on its default
  // (`public`) would trigger an infinite copy loop into
  // public/vendor/martis-user/. Disable it here.
  publicDir: false,
  resolve: {
    // Array form so we can pin the more-specific patterns FIRST.
    // Vite/rollup resolves aliases in declaration order; with the
    // record form, `react` would prefix-match `react/jsx-runtime`
    // and `react-router-dom`, rewriting them as `<react-shim>/...`
    // which fails the build with "Not a directory".
    alias: [
      // React first — most-specific patterns top.
      {find: 'react/jsx-runtime', replacement: jsxRuntimeShim},
      {find: /^react$/, replacement: reactShim},
      {find: /^react-dom$/, replacement: reactShim},
      // Re-exposed 3rd-party modules (host bundles them; consumer
      // doesn't need to install).
      {find: /^react-router-dom$/, replacement: routerShim},
      {find: /^react-i18next$/, replacement: i18nextShim},
      {find: /^@tanstack\/react-query$/, replacement: queryShim},
      // The public `@martis/runtime` surface.
      {find: '@martis/runtime', replacement: runtimeShim},
      // Legacy paths from pre-v1.10 stubs (auth pages used these
      // before `@martis/runtime` existed). Keeping the aliases lets
      // apps with old stubs upgrade without re-running install.
      {find: /^@\/contexts\//, replacement: runtimeShim},
      {find: /^@\/lib\//, replacement: runtimeShim},
      {find: /^@\/components\/auth\//, replacement: runtimeShim},
      {find: /^@martis\/martis\//, replacement: runtimeShim},
    ],
  },
  build: {
    lib: {
      entry: path.resolve(__dirname, 'resources/js/martis-extensions/index.ts'),
      formats: ['es'],
      fileName: () => 'extensions.js',
    },
    outDir: 'public/vendor/martis-user',
    emptyOutDir: true,
    sourcemap: true,
  },
})
