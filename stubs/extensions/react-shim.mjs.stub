/**
 * Martis React shim (v1.9.3+).
 *
 * Why this file exists: ES module bundles can't use `output.globals`
 * to redirect a bare `import "react"` to a runtime global the way
 * UMD/IIFE builds can. Marking `react` as external in vite library
 * mode produces `import {useEffect} from "react"` in the published
 * bundle, which the browser then refuses to load with
 * "TypeError: Failed to resolve module specifier 'react'".
 *
 * Solution: this file re-exports every React surface the consumer
 * extensions need from `window.Martis.react`, and the consumer's
 * `vite.extensions.config.ts` aliases `react` → this file. Vite then
 * inlines the shim into the bundle at build time, so `import "react"`
 * resolves to a one-line getter against `window.Martis.react`. No
 * second React copy, no module-resolution error, no runtime surprise.
 *
 * The Martis SPA exposes `window.Martis.react` from `app.tsx` *before*
 * the consumer bundle is dynamic-imported (v1.9.2 awaits the import),
 * so by the time this shim runs the global is always populated.
 */

const R =
  typeof window !== 'undefined' && window.Martis && window.Martis.react
    ? window.Martis.react
    : null

if (R === null) {
  throw new Error(
    '[martis-react-shim] window.Martis.react not available — the Martis SPA must boot before consumer extensions load. Check that MARTIS_EXTENSIONS only lists URLs imported by app.tsx (v1.9.2+ awaits these imports automatically).',
  )
}

export default R

// ---- React core -----------------------------------------------------------
export const Children = R.Children
export const Component = R.Component
export const Fragment = R.Fragment
export const Profiler = R.Profiler
export const PureComponent = R.PureComponent
export const StrictMode = R.StrictMode
export const Suspense = R.Suspense
export const cloneElement = R.cloneElement
export const createContext = R.createContext
export const createElement = R.createElement
export const createFactory = R.createFactory
export const createRef = R.createRef
export const forwardRef = R.forwardRef
export const isValidElement = R.isValidElement
export const lazy = R.lazy
export const memo = R.memo
export const startTransition = R.startTransition
export const version = R.version

// ---- Hooks ----------------------------------------------------------------
export const useCallback = R.useCallback
export const useContext = R.useContext
export const useDebugValue = R.useDebugValue
export const useDeferredValue = R.useDeferredValue
export const useEffect = R.useEffect
export const useId = R.useId
export const useImperativeHandle = R.useImperativeHandle
export const useInsertionEffect = R.useInsertionEffect
export const useLayoutEffect = R.useLayoutEffect
export const useMemo = R.useMemo
export const useReducer = R.useReducer
export const useRef = R.useRef
export const useState = R.useState
export const useSyncExternalStore = R.useSyncExternalStore
export const useTransition = R.useTransition

// ---- React 18 / 19 newcomers (gated so older Martis builds don't break) ---
export const use = R.use
export const useActionState = R.useActionState
export const useFormStatus = R.useFormStatus
export const useOptimistic = R.useOptimistic
