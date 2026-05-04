/**
 * `@martis/runtime` shim (v1.10.0+).
 *
 * Re-exports the public Martis runtime surface from
 * `window.Martis.runtime`. The consumer's `vite.extensions.config.ts`
 * aliases `@martis/runtime` (and the legacy `@/contexts/*`,
 * `@/lib/*`, `@/components/auth/*`, `@martis/martis/*` paths) to
 * this file so override stubs can `import {useAuth, api, AuthFrame,
 * ...} from '@martis/runtime'` and the bundle reads everything off
 * the host SPA's React tree at runtime — no duplicate React, no
 * unresolved bare specifiers.
 *
 * The Martis SPA (`app.tsx`, since v1.10.0) populates
 * `window.Martis.runtime` BEFORE the consumer extension bundle is
 * loaded (`app.tsx` awaits all `MARTIS_EXTENSIONS` URLs since v1.9.2),
 * so by the time this shim runs the global is always populated.
 *
 * See `martis-package/docs/runtime-api.md` for the full surface and
 * the semver contract.
 */

const R =
  typeof window !== 'undefined' && window.Martis && window.Martis.runtime
    ? window.Martis.runtime
    : null

if (R === null) {
  throw new Error(
    '[martis-runtime-shim] window.Martis.runtime not available — Martis v1.10+ exposes it from app.tsx. Older Martis builds (≤v1.9.x) do not ship this surface. Upgrade the package or override the consumer alias to point at a custom shim.',
  )
}

// Hooks
export const useAuth = R.useAuth
export const useToast = R.useToast
export const useToastSafe = R.useToastSafe
export const useIsMobile = R.useIsMobile

// Auth context exceptions
export const TwoFactorRequiredError = R.TwoFactorRequiredError
export const EmailVerificationRequiredError = R.EmailVerificationRequiredError

// Provider
export const AuthProvider = R.AuthProvider

// Lib
export const api = R.api
export const ApiError = R.ApiError
export const config = R.config

// Components
export const AuthFrame = R.AuthFrame
export const Sidebar = R.Sidebar
export const Topbar = R.Topbar
export const Footer = R.Footer

// `react-router-dom` re-exports flattened on the top level so legacy
// stub imports `import {useNavigate} from 'react-router-dom'` resolve
// when the consumer's vite alias points the bare module specifier
// here. See `react-router-dom-shim.mjs` for the dedicated shim.
const RR = R.reactRouterDom ?? {}
export const Link = RR.Link
export const NavLink = RR.NavLink
export const Outlet = RR.Outlet
export const Navigate = RR.Navigate
export const useNavigate = RR.useNavigate
export const useParams = RR.useParams
export const useSearchParams = RR.useSearchParams
export const useLocation = RR.useLocation

const I = R.reactI18next ?? {}
export const useTranslation = I.useTranslation
export const Trans = I.Trans

const Q = R.tanstackReactQuery ?? {}
export const useQuery = Q.useQuery
export const useMutation = Q.useMutation
export const useQueryClient = Q.useQueryClient

export default R
