import { existsSync, lstatSync } from 'node:fs'
import path from 'node:path'

/**
 * The Vitest config fragment of the Martis test kit (v2.3.0), for
 * `mergeConfig()` in a consumer's `vitest.config.ts`:
 *
 *     import { defineConfig, mergeConfig } from 'vitest/config'
 *     import react from '@vitejs/plugin-react'
 *     import { martisExtensionTestConfig } from './vendor/martis/martis/dist/testing/vitest.mjs'
 *
 *     export default mergeConfig(defineConfig({ plugins: [react()] }), martisExtensionTestConfig())
 *
 * It sends the extension specifiers to the shims `martis:install` published,
 * as `vite.extensions.config.ts` does, except React, its JSX runtime and
 * ReactDOM: those stay the app's own, so the extension, the test runtime and
 * Testing Library share one React. `resolve.dedupe` pins `react` and
 * `react-dom` to the app's copy whatever the symlinks, so a Martis package that
 * has its own node_modules cannot bring a second one; the icon library is
 * inlined for the same reason. `@martis/testing` is the test runtime, which
 * also runs as the setup file. See docs/testing-extensions.md.
 *
 * @param {{ root?: string, packageDir?: string }} [options]
 *   `root`: the app's root (the current directory by default);
 *   `packageDir`: the Martis package below it (`vendor/martis/martis`).
 */
export function martisExtensionTestConfig(options = {}) {
  const root = options.root ?? process.cwd()
  const packageDir = path.resolve(root, options.packageDir ?? 'vendor/martis/martis')
  const testing = path.join(packageDir, 'dist/testing/testing.mjs')
  const shims = path.resolve(root, 'resources/js/martis-extensions/.shims')
  const runtime = path.join(shims, 'runtime.mjs')

  if (!existsSync(testing)) {
    throw new Error(`[martis] martisExtensionTestConfig(): no test runtime at ${testing}. Run composer install, or pass { packageDir } when the package lives elsewhere.`)
  }
  if (!existsSync(runtime)) {
    throw new Error(`[martis] martisExtensionTestConfig(): no shims at ${shims}. Run php artisan martis:install, or php artisan vendor:publish --tag=martis-extension-shims.`)
  }

  return {
    resolve: {
      // A path repository symlinks the package: keep the link's path, so the
      // runtime resolves React from the app's node_modules, inside the root.
      preserveSymlinks: lstatSync(packageDir).isSymbolicLink(),
      // The real path of a symlinked package may hold its own React: resolve
      // react (and its JSX runtime) and react-dom from the app root, always.
      dedupe: ['react', 'react-dom'],
      alias: [
        { find: /^react-router-dom$/, replacement: path.join(shims, 'react-router-dom.mjs') },
        { find: /^react-router$/, replacement: path.join(shims, 'react-router-dom.mjs') },
        { find: /^react-i18next$/, replacement: path.join(shims, 'react-i18next.mjs') },
        { find: /^@tanstack\/react-query$/, replacement: path.join(shims, 'tanstack-react-query.mjs') },
        { find: /^@martis\/testing$/, replacement: testing },
        { find: '@martis/runtime', replacement: runtime },
        { find: /^@\/contexts\/.*$/, replacement: runtime },
        { find: /^@\/lib\/.*$/, replacement: runtime },
        { find: /^@\/components\/auth\/.*$/, replacement: runtime },
        { find: /^@martis\/martis\/.*$/, replacement: runtime },
        { find: /^@\/components\/fields\/types$/, replacement: runtime },
      ],
    },
    test: {
      environment: 'jsdom',
      setupFiles: [testing],
      // Vitest runs a dependency under Node unless it is inlined, and Node
      // resolves its react from the dependency's real path, the package's own
      // node_modules. Inlined, it goes through the dedupe above.
      server: { deps: { inline: [/@phosphor-icons\/react/] } },
    },
  }
}
