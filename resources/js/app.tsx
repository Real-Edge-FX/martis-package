import '../css/martis.css'
import * as React from 'react'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
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
  version: __MARTIS_VERSION__,
}

const extensionUrls = window.MartisConfig?.extensions ?? []
for (const url of extensionUrls) {
  if (typeof url !== 'string' || url === '') continue
  // /* @vite-ignore */ tells Vite NOT to pre-resolve the URL at build
  // time; the import target is supplied at runtime by the consumer.
  import(/* @vite-ignore */ url).catch((err: unknown) => {
    // eslint-disable-next-line no-console
    console.error('[martis] failed to load extension', url, err)
  })
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
  initI18n()
    .then(() => {
      createRoot(container).render(<App />)
    })
    .catch(() => {
      // If i18n fails to init, render anyway without translations
      createRoot(container).render(<App />)
    })
}
