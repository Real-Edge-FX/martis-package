// The PrimeReact theme (lara, compiled with the Martis tokens) loads first so
// the Martis stylesheet can refine it.
import '../sass/primereact/theme.scss'
import '../css/martis.css'
import * as React from 'react'
import { StrictMode } from 'react'
import * as ReactJsxRuntime from 'react/jsx-runtime'
import { createRoot } from 'react-dom/client'
import { MemoryRouter } from 'react-router'
import { martisRuntime } from '@/lib/martisRuntime'
import { reactDomClientHandle, reactDomHandle } from '@/lib/reactDomHandles'
import { RouterProvider } from 'react-router/dom'
import { QueryClientProvider } from '@tanstack/react-query'
import { PrimeReactProvider } from 'primereact/api'
import { queryClient } from '@/lib/query'
import { AuthProvider } from '@/contexts/AuthContext'
import { ThemeProvider } from '@/contexts/ThemeContext'
import { PreferencesProvider } from '@/contexts/PreferencesContext'
import { DynamicCrumbProvider } from '@/contexts/DynamicCrumbContext'
import { GateProvider } from '@/contexts/GateContext'
import { GateModal } from '@/components/GateModal'
import { ToastProvider } from '@/contexts/ToastContext'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import type { AppRouter } from '@/router'
import { ToastContainer } from '@/components/Toast'
import { LanguageSwitchOverlay } from '@/components/LanguageSwitchOverlay/LanguageSwitchOverlay'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'
import { bootAppRouter } from '@/lib/appBoot'
import { componentRegistry } from '@/lib/componentRegistry'
import { config } from '@/lib/config'
import { readCspNonce } from '@/lib/cspNonce'
import { PanelForbiddenPage } from '@/pages/PanelForbidden'
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
  // The public ReactDOM 18 API (v2.10.0): the `react-dom` and
  // `react-dom/client` shims re-export from these, so a library that
  // imports `unstable_batchedUpdates` or `createRoot` runs on the host's
  // ReactDOM. See `lib/reactDomHandles.ts`.
  reactDom: reactDomHandle,
  reactDomClient: reactDomClientHandle,
  // `@martis/runtime` public surface (v1.10+). Consumer-extension
  // shims re-export from here so override stubs can `import {useAuth,
  // api, AuthFrame, ...} from '@martis/runtime'` and the bundle
  // resolves everything against the host SPA's React tree.
  // See `lib/martisRuntime.ts` for the full export list and the
  // semver contract.
  runtime: martisRuntime,
  version: __MARTIS_VERSION__,
}

// PrimeReact injects its component styles as <style> elements at run time; a
// Content-Security-Policy nonce (published by the shell) goes on them.
const cspNonce = readCspNonce()
const primeReactOptions = cspNonce !== null ? { nonce: cspNonce } : undefined

function App({ router }: { router: AppRouter }) {
  return (
    <StrictMode>
      <ErrorBoundary>
        <PrimeReactProvider value={primeReactOptions}>
          <QueryClientProvider client={queryClient}>
            <AuthProvider>
              <PreferencesProvider>
                <ThemeProvider>
                  <ToastProvider>
                    <DynamicCrumbProvider>
                      <GateProvider>
                        <RouterProvider router={router} />
                        <GateModal />
                        <ToastContainer />
                        <LanguageSwitchOverlay />
                      </GateProvider>
                    </DynamicCrumbProvider>
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

// A user the `viewMartis` gate refuses gets this instead of the panel: no
// providers that call protected endpoints, only the screen and sign-out.
function PanelForbiddenApp() {
  return (
    <StrictMode>
      <MemoryRouter>
        <PanelForbiddenPage />
      </MemoryRouter>
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
  const panelForbidden = config.panelForbidden === true

  // `bootAppRouter()` builds the router only once the extension bundles
  // have registered their routes and auth page overrides (v2.2.0; before,
  // it was built when `@/router` was imported, ahead of every bundle).
  void bootAppRouter(panelForbidden).then((router) => {
    createRoot(container).render(router === null ? <PanelForbiddenApp /> : <App router={router} />)
  })
}
