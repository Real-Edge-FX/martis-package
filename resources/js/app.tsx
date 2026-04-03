import '../css/martis.css'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'
import { queryClient } from '@/lib/query'
import { AuthProvider } from '@/contexts/AuthContext'
import { ThemeProvider } from '@/contexts/ThemeContext'
import { ToastProvider } from '@/contexts/ToastContext'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { router } from '@/router'
import { registerDefaultFields } from '@/components/fields'
import { initI18n } from '@/lib/i18n'

// Register all default field renderers into the global component registry
registerDefaultFields()

const container = document.getElementById('martis-root')

if (container) {
  initI18n().then(() => {
    createRoot(container).render(
      <StrictMode>
        <ErrorBoundary>
          <ThemeProvider>
            <QueryClientProvider client={queryClient}>
              <ToastProvider>
                <AuthProvider>
                  <RouterProvider router={router} />
                </AuthProvider>
              </ToastProvider>
            </QueryClientProvider>
          </ThemeProvider>
        </ErrorBoundary>
      </StrictMode>,
    )
  }).catch(() => {
    // If i18n fails to init, render anyway without translations
    createRoot(container).render(
      <StrictMode>
        <ErrorBoundary>
          <ThemeProvider>
            <QueryClientProvider client={queryClient}>
              <ToastProvider>
                <AuthProvider>
                  <RouterProvider router={router} />
                </AuthProvider>
              </ToastProvider>
            </QueryClientProvider>
          </ThemeProvider>
        </ErrorBoundary>
      </StrictMode>,
    )
  })
}
