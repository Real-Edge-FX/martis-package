import { createContext, useContext, useLayoutEffect, useState, type ReactNode } from 'react'
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
   * Read once, when the provider mounts.
   */
  config?: Record<string, unknown>
  /** The i18next language, `en` by default. */
  locale?: string
  /**
   * Translations for `locale`, by namespace: `{ reports: { title: 'Reports' } }`,
   * removed when the provider unmounts. A key without one renders as the key.
   * Read once, when the provider mounts.
   */
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
  const [overrides] = useState<Overrides>(() => ({ config: { ...(config ?? {}) }, locale, translations: translations ?? {} }))
  const [ready, setReady] = useState(false)

  // The config and the translations are applied in a layout effect, not
  // during render: StrictMode's mount, cleanup and mount again then apply,
  // restore and re-apply them, and the tree renders once they are in place.
  useLayoutEffect(() => {
    mountOverrides(overrides)
    setReady(true)

    return () => unmountOverrides(overrides)
  }, [overrides])

  if (!ready) return null

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

/** What one mounted provider puts on top of the app's config and translations. */
interface Overrides {
  config: Record<string, unknown>
  locale: string
  translations: Record<string, Record<string, unknown>>
}

/** The providers currently mounted, in mount order: a later one wins a key both set. */
const mounted: Overrides[] = []

/** Each config key a mounted provider sets, with the app's own value before the first of them. */
const originalConfig = new Map<string, { had: boolean; value: unknown }>()

/** Each translation bundle a mounted provider adds to, with its content before the first of them. */
const originalBundles = new Map<string, { locale: string; namespace: string; bundle: Record<string, unknown> | undefined }>()

const hasOwn = (object: object, key: string): boolean => Object.prototype.hasOwnProperty.call(object, key)

function mountOverrides(overrides: Overrides): void {
  const target = martisConfig as Record<string, unknown>
  for (const key of Object.keys(overrides.config)) {
    if (!originalConfig.has(key)) originalConfig.set(key, { had: hasOwn(target, key), value: target[key] })
  }
  for (const namespace of Object.keys(overrides.translations)) {
    const id = `${overrides.locale}\u0000${namespace}`
    if (!originalBundles.has(id)) {
      const bundle = i18n.hasResourceBundle(overrides.locale, namespace) ? structuredClone(i18n.getResourceBundle(overrides.locale, namespace) as Record<string, unknown>) : undefined
      originalBundles.set(id, { locale: overrides.locale, namespace, bundle })
    }
  }

  mounted.push(overrides)
  settle()
  if (i18n.language !== overrides.locale) void i18n.changeLanguage(overrides.locale)
}

function unmountOverrides(overrides: Overrides): void {
  const index = mounted.lastIndexOf(overrides)
  if (index !== -1) mounted.splice(index, 1)
  settle()
}

/**
 * Rebuilds every key and bundle a provider touched from the app's original
 * plus the providers still mounted, in order. One that none of them sets any
 * more is back to the original, and forgotten.
 */
function settle(): void {
  const target = martisConfig as Record<string, unknown>
  for (const [key, original] of originalConfig) {
    const owner = [...mounted].reverse().find((overrides) => hasOwn(overrides.config, key))
    if (owner !== undefined) {
      target[key] = owner.config[key]
      continue
    }
    if (original.had) target[key] = original.value
    else delete target[key]
    originalConfig.delete(key)
  }

  for (const [id, { locale, namespace, bundle }] of originalBundles) {
    const owners = mounted.filter((overrides) => overrides.locale === locale && hasOwn(overrides.translations, namespace))
    i18n.removeResourceBundle(locale, namespace)
    if (bundle !== undefined) i18n.addResourceBundle(locale, namespace, structuredClone(bundle), true, true)
    for (const owner of owners) i18n.addResourceBundle(locale, namespace, owner.translations[namespace], true, true)
    if (owners.length === 0) originalBundles.delete(id)
  }
}
