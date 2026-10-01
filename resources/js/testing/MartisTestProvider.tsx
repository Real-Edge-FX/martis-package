import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { PrimeReactProvider } from 'primereact/api'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter } from 'react-router'
import { RouterProvider } from 'react-router/dom'
import i18n from '@/lib/i18n'
import { config as martisConfig } from '@/lib/config'
import type { User } from '@/types'
import { AuthProvider } from '@/contexts/AuthContext'
import { PreferencesProvider, type Preferences } from '@/contexts/PreferencesContext'
import { ThemeProvider } from '@/contexts/ThemeContext'
import { ToastProvider } from '@/contexts/ToastContext'
import { DynamicCrumbProvider } from '@/contexts/DynamicCrumbContext'
import { GateProvider } from '@/contexts/GateContext'
import { GateModal } from '@/components/GateModal'
import { ActionResponseModalProvider } from '@/components/Actions/ActionResponseModalHost'

/** The user a test renders as unless it passes `user`. */
export const defaultTestUser: User = { id: 1, name: 'Test User', email: 'test@example.com', panel_access: true }

export interface MartisTestProviderProps {
  children: ReactNode
  /** The signed-in user, `defaultTestUser` by default; `null` renders as a guest. */
  user?: User | null
  /** Preferences on top of the defaults (theme, accent, density...), kept in memory. */
  preferences?: Partial<Preferences>
  /**
   * Keys merged into `window.MartisConfig` while the provider is mounted
   * (`{ profile: { ... }, auth: { ... } }`), restored when it unmounts.
   * Values the runtime read once on load (`basePath`) keep their defaults.
   */
  config?: Record<string, unknown>
  /** The i18next language, `en` by default. */
  locale?: string
  /** Translations for `locale`, by namespace: `{ reports: { title: 'Reports' } }`. A key without one renders as the key. */
  translations?: Record<string, Record<string, unknown>>
  /** The React Query client; a new one with retries off by default. */
  queryClient?: QueryClient
  /** The route pattern the children render at, `*` by default (`reports/:reportId` gives them `useParams()`). */
  path?: string
  /** The URL the router starts at, `/` by default. */
  initialPath?: string
}

const ChildrenContext = createContext<ReactNode>(null)

/** The route element: the children, under the action response host as in the shell. */
function RouteChildren() {
  return <ActionResponseModalProvider>{useContext(ChildrenContext)}</ActionResponseModalProvider>
}

/**
 * Renders a consumer extension (a Tool, a card, a field, an override) inside
 * the providers the Martis shell gives it, for Vitest or a Playwright
 * harness page (v2.3.0):
 * - the real auth, preferences, theme, toast, crumb and gate providers;
 * - React Query and a data router;
 * - the i18next instance the shims serve.
 *
 * Nothing reaches a server unless the component calls the API, which a test
 * stubs (`fetch`) or spies (`runtime.api`). See docs/testing-extensions.md.
 */
export function MartisTestProvider({
  children,
  user = defaultTestUser,
  preferences = {},
  config,
  locale = 'en',
  translations,
  queryClient,
  path = '*',
  initialPath = '/',
}: MartisTestProviderProps) {
  const [client] = useState(() => queryClient ?? new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } }))
  const [router] = useState(() => createMemoryRouter([{ path, element: <RouteChildren /> }], { initialEntries: [initialPath] }))
  useState(() => applyTranslations(locale, translations))
  useState(() => applyConfig(config))

  useEffect(() => restoreConfig, [])

  return (
    <PrimeReactProvider>
      <QueryClientProvider client={client}>
        <AuthProvider initialUser={user}>
          <PreferencesProvider initialPreferences={{ locale, ...preferences }} syncWithServer={false}>
            <ThemeProvider>
              <ToastProvider>
                <DynamicCrumbProvider>
                  <GateProvider>
                    <ChildrenContext.Provider value={children}>
                      <RouterProvider router={router} />
                    </ChildrenContext.Provider>
                    <GateModal />
                  </GateProvider>
                </DynamicCrumbProvider>
              </ToastProvider>
            </ThemeProvider>
          </PreferencesProvider>
        </AuthProvider>
      </QueryClientProvider>
    </PrimeReactProvider>
  )
}

function applyTranslations(locale: string, translations: MartisTestProviderProps['translations']): void {
  for (const [namespace, keys] of Object.entries(translations ?? {})) {
    i18n.addResourceBundle(locale, namespace, keys, true, true)
  }
  if (i18n.language !== locale) void i18n.changeLanguage(locale)
}

/** The values `config` replaced, kept once per key, so a double render (StrictMode) restores the originals. */
const replacedConfig = new Map<string, { had: boolean; value: unknown }>()

function applyConfig(overrides: Record<string, unknown> | undefined): void {
  const target = martisConfig as Record<string, unknown>
  for (const [key, value] of Object.entries(overrides ?? {})) {
    if (!replacedConfig.has(key)) {
      replacedConfig.set(key, { had: Object.prototype.hasOwnProperty.call(target, key), value: target[key] })
    }
    target[key] = value
  }
}

function restoreConfig(): void {
  const target = martisConfig as Record<string, unknown>
  for (const [key, { had, value }] of replacedConfig) {
    if (had) target[key] = value
    else delete target[key]
  }
  replacedConfig.clear()
}
