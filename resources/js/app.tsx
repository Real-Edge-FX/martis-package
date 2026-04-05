import '../css/martis.css'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'
import { PrimeReactProvider } from 'primereact/api'
import { queryClient } from '@/lib/query'
import { AuthProvider } from '@/contexts/AuthContext'
import { ThemeProvider } from '@/contexts/ThemeContext'
import { ToastProvider } from '@/contexts/ToastContext'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { router } from '@/router'
import { ToastContainer } from '@/components/Toast'
import { registerDefaultFields } from '@/components/fields'
import { registerBuiltinFieldOverrides } from '@/components/field-overrides'
import { initI18n } from '@/lib/i18n'

// Register all default field renderers into the global component registry
registerDefaultFields()

// Register built-in field override components (status-pills, priority-badge, etc.)
registerBuiltinFieldOverrides()

function App() {
  return (
    <StrictMode>
      <ErrorBoundary>
        <PrimeReactProvider>
          <ThemeProvider>
            <QueryClientProvider client={queryClient}>
              <ToastProvider>
                <AuthProvider>
                  <RouterProvider router={router} />
                  <ToastContainer />
                </AuthProvider>
              </ToastProvider>
            </QueryClientProvider>
          </ThemeProvider>
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
