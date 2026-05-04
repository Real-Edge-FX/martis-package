/**
 * Martis React JSX-runtime shim (v1.9.3+).
 *
 * Companion to `react-shim.mjs`. JSX-transpiled code emits
 * `import {jsx, jsxs, Fragment} from "react/jsx-runtime"`, which is
 * a separate module from "react" and needs its own shim. Vite aliases
 * `react/jsx-runtime` to this file.
 *
 * The Martis SPA bundles its own JSX runtime through the @vitejs/plugin-react
 * setup, so `window.Martis.reactJsxRuntime` is the canonical handle.
 * If the host hasn't exposed it (older Martis builds), we fall back to
 * the React object's `jsx` exports — modern React 18+ ships them.
 */

const root =
  typeof window !== 'undefined' && window.Martis ? window.Martis : null

const runtime =
  (root && root.reactJsxRuntime) ??
  (root && root.react && root.react.jsxRuntime) ??
  // React 18+ inlines jsx/jsxs/jsxDEV on the React object itself for
  // the new transform — use that as a final fallback so older Martis
  // bundles don't crash.
  (root && root.react ? {jsx: root.react.jsx, jsxs: root.react.jsxs, jsxDEV: root.react.jsxDEV, Fragment: root.react.Fragment} : null)

if (runtime === null) {
  throw new Error(
    '[martis-jsx-runtime-shim] window.Martis.reactJsxRuntime not available — Martis v1.9.3+ exposes it from app.tsx; older builds need a jsx-runtime polyfill.',
  )
}

export const jsx = runtime.jsx
export const jsxs = runtime.jsxs
export const jsxDEV = runtime.jsxDEV
export const Fragment = runtime.Fragment ?? (root && root.react ? root.react.Fragment : undefined)
