import '../css/martis.css'
import * as React from 'react'
import { StrictMode } from 'react'
import * as ReactJsxRuntime from 'react/jsx-runtime'
import { createRoot } from 'react-dom/client'
import { martisRuntime } from '@/lib/martisRuntime'
import { RouterProvider } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'
import { PrimeReactProvider } from 'primereact/api'
import { queryClient } from '@/lib/query'
import { AuthProvider } from '@/contexts/AuthContext'
import { ThemeProvider } from '@/contexts/ThemeContext'
import { PreferencesProvider } from '@/contexts/PreferencesContext'
import { ToastProvider } from '@/contexts/ToastContext'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { router } from '@/router'
import { ToastContainer } from '@/components/Toast'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'
import { initI18n } from '@/lib/i18n'
import { componentRegistry } from '@/lib/componentRegistry'
import { DrawerCreate } from '@/components/overrides/DrawerCreate'
import { DrawerUpdate } from '@/components/overrides/DrawerUpdate'
import { DemoCustomAction } from '@/components/Actions/DemoCustomAction'
import { DrawerDetail } from '@/components/overrides/DrawerDetail'
import { DrawerQuick } from '@/components/overrides/DrawerQuick'
import { SystemStatusDemo } from '@/components/tools/SystemStatusDemo'

// Register all default field renderers into the global component registry
registerDefaultFields()

// Register built-in override components (drawers, modals, etc.)
componentRegistry.register('martis:drawer-create', DrawerCreate as never)
componentRegistry.register('martis:drawer-update', DrawerUpdate as never)
componentRegistry.register('martis:drawer-detail', DrawerDetail as never)
componentRegistry.register('martis:drawer-quick', DrawerQuick as never)
componentRegistry.register('demo-custom-action', DemoCustomAction as never)

// Built-in Tools demo component (v0.10) — apps that want a quick
// "system overview" page can ship a Tool subclass binding to this key.
componentRegistry.register('martis:tool:system-status-demo', SystemStatusDemo as never)

// -----------------------------------------------------------------------------
// Runtime extension surface (v1.8.19)
//
// Expose a stable global so consumer-built ESM bundles can register
// components, override fields, etc. without rebuilding the Martis
// package.
//
// Consumers ship their own Vite/Rollup/esbuild bundle that:
//   1. Marks `react` external mapped to `window.Martis.react` to avoid
//      duplicating React (size + duplicate-instance hazards).
//   2. Reads `componentRegistry` via `window.Martis.componentRegistry`.
//   3. Calls `componentRegistry.register('tool:my-key', MyComponent)`.
//
// Bundle URLs are listed in `config('martis.extensions')` (sourced
// from the `MARTIS_EXTENSIONS` env, comma-separated). app.blade.php
// emits the resolved array as `window.MartisConfig.extensions` and
// the loader below dynamic-imports each one. Failures are isolated:
// one broken extension cannot take down the whole panel.
// -----------------------------------------------------------------------------

window.Martis = {
  ...(window.Martis ?? {}),
  componentRegistry,
  react: React,
  // The JSX runtime is a separate module from React itself
  // (`react/jsx-runtime`) and the consumer-extension shim re-exports
  // from this handle. Exposing it here lets the v1.9.3+ shim resolve
  // jsx/jsxs/Fragment without the consumer needing to bundle a
  // second React copy.
  reactJsxRuntime: ReactJsxRuntime,
  // `@martis/runtime` public surface (v1.10+). Consumer-extension
  // shims re-export from here so override stubs can `import {useAuth,
  // api, AuthFrame, ...} from '@martis/runtime'` and the bundle
  // resolves everything against the host SPA's React tree.
  // See `lib/martisRuntime.ts` for the full export list and the
  // semver contract.
  runtime: martisRuntime,
  version: __MARTIS_VERSION__,
}

/**
 * Load every extension bundle listed in `window.MartisConfig.extensions`
 * (sourced from the `MARTIS_EXTENSIONS` env, comma-separated).
 *
 * v1.8.19 shipped this as fire-and-forget: imports started, React
 * mounted, the SPA raced the network. On a cold-cache navigation
 * straight to `/martis/tools/{key}` the ToolPage queried the registry
 * before the bundle had registered the component, the placeholder
 * fired, and a subsequent registry write never re-rendered the page —
 * so the user saw "No React component is registered…" forever.
 *
 * v1.9.2 awaits every import (with a 5s per-URL timeout safety net so
 * a hung extension cannot keep the whole panel hidden forever) before
 * mounting React. Failures stay isolated — one broken bundle logs and
 * the rest still load — and the slowest case is a single round-trip
 * for the cached extensions.js, which is well under the i18n init
 * cost we already wait on below.
 */
const EXTENSION_LOAD_TIMEOUT_MS = 5_000

async function loadConsumerExtensions(): Promise<void> {
  const extensionUrls = window.MartisConfig?.extensions ?? []

  await Promise.all(
    extensionUrls
      .filter((url): url is string => typeof url === 'string' && url !== '')
      .map((url) => {
        const load = import(/* @vite-ignore */ url).catch((err: unknown) => {
          // eslint-disable-next-line no-console
          console.error('[martis] failed to load extension', url, err)
        })
        const timeout = new Promise<void>((resolve) =>
          setTimeout(() => {
            // eslint-disable-next-line no-console
            console.warn('[martis] extension load exceeded', EXTENSION_LOAD_TIMEOUT_MS, 'ms; mounting without it', url)
            resolve()
          }, EXTENSION_LOAD_TIMEOUT_MS),
        )
        return Promise.race([load, timeout])
      }),
  )
}

function App() {
  return (
    <StrictMode>
      <ErrorBoundary>
        <PrimeReactProvider>
          <QueryClientProvider client={queryClient}>
            <AuthProvider>
              <PreferencesProvider>
                <ThemeProvider>
                  <ToastProvider>
                    <RouterProvider router={router} />
                    <ToastContainer />
                  </ToastProvider>
                </ThemeProvider>
              </PreferencesProvider>
            </AuthProvider>
          </QueryClientProvider>
        </PrimeReactProvider>
      </ErrorBoundary>
    </StrictMode>
  )
}

const container = document.getElementById('martis-root')

if (container) {
  // Order matters: i18n + extensions both need to be in place before
  // the first render so ToolPage / FieldRenderer / Card resolvers
  // find their registrations on the very first lookup. Anything that
  // throws/times-out short-circuits to a render so a slow CDN cannot
  // black-hole the panel.
  Promise.all([
    initI18n().catch(() => undefined),
    loadConsumerExtensions().catch(() => undefined),
  ]).then(() => {
    createRoot(container).render(<App />)
  })
}
