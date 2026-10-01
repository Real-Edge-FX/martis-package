// checkReactMajor runs first: a test run on another React major than the panel's fails before anything loads.
import './checkReactMajor'
// installGlobals runs next: the rest of the runtime reads window.MartisConfig when it loads.
import './installGlobals'
import '../../css/martis.css'
import '../../sass/primereact/theme.scss'
import * as React from 'react'
import * as ReactJsxRuntime from 'react/jsx-runtime'
import { initReactI18next } from 'react-i18next'
import i18n from '@/lib/i18n'
import { martisRuntime } from '@/lib/martisRuntime'
import { componentRegistry } from '@/lib/componentRegistry'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

export { MartisTestProvider, defaultTestUser } from './MartisTestProvider'
export type { MartisTestProviderProps } from './MartisTestProvider'

/*
 * The Martis test runtime (v2.3.0): `npm run build:testing` turns this
 * module into `dist/testing/`, a development build of the runtime for a
 * consumer's Vitest run or Playwright harness (docs/testing-extensions.md).
 * Importing it fills `window.Martis` as the SPA's app.tsx does, so the shims
 * `martis:install` published (`@martis/runtime`, `react-router-dom`,
 * `react-i18next`, `@tanstack/react-query`) work unchanged in a test.
 */

registerDefaultFields()

if (!i18n.isInitialized) {
  void i18n.use(initReactI18next).init({
    resources: {},
    lng: 'en',
    fallbackLng: 'en',
    initAsync: false,
    interpolation: { escapeValue: false },
    react: { useSuspense: false },
  })
}

allowDataRouterNavigation()

window.Martis = {
  ...(window.Martis ?? {}),
  componentRegistry,
  react: React,
  reactJsxRuntime: ReactJsxRuntime,
  runtime: martisRuntime,
  version: __MARTIS_VERSION__,
}

/**
 * React Router's data router builds a `Request` with an AbortSignal on every
 * navigation. Under jsdom the signal is jsdom's, which Node's `Request`
 * refuses, so a navigation never completes. When the global `Request`
 * refuses one, it is replaced by a subclass that drops the signal; the
 * router uses it only to abort loaders, which a test runtime does not run.
 * A real browser (Playwright) keeps its own `Request`.
 */
function allowDataRouterNavigation(): void {
  if (typeof globalThis.Request !== 'function' || typeof AbortController !== 'function') return
  try {
    new globalThis.Request('http://localhost/', { signal: new AbortController().signal })
  } catch {
    const NativeRequest = globalThis.Request
    globalThis.Request = class extends NativeRequest {
      constructor(input: RequestInfo | URL, init?: RequestInit) {
        super(input, init?.signal ? { ...init, signal: undefined } : init)
      }
    }
  }
}
