import '../css/martis.css'
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

// Load user-defined component overrides (if any)
try {
  // @ts-expect-error — @user alias may not exist in all consumer apps
  import('@user/martis/boot').catch(() => {
    // No user boot module — that's fine, use only defaults
  })
} catch {
  // Static analysis fallback
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
