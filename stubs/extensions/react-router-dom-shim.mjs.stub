/**
 * `react-router-dom` shim (v1.10.0+).
 *
 * The consumer's `vite.extensions.config.ts` aliases bare
 * `react-router-dom` imports to this file so override stubs can
 * keep using `import { useNavigate } from 'react-router-dom'`
 * without the consumer running `npm install react-router-dom`. The
 * package's host SPA already bundles react-router-dom; this shim
 * just re-exports the surface from `window.Martis.runtime.reactRouterDom`.
 */

const RR =
  typeof window !== 'undefined' &&
  window.Martis &&
  window.Martis.runtime &&
  window.Martis.runtime.reactRouterDom
    ? window.Martis.runtime.reactRouterDom
    : null

if (RR === null) {
  throw new Error(
    '[react-router-dom-shim] window.Martis.runtime.reactRouterDom not available — Martis v1.10+ exposes it. Upgrade the package or install react-router-dom directly and remove the alias.',
  )
}

export const Link = RR.Link
export const NavLink = RR.NavLink
export const Outlet = RR.Outlet
export const Navigate = RR.Navigate
export const Route = RR.Route
export const Routes = RR.Routes
export const BrowserRouter = RR.BrowserRouter
export const HashRouter = RR.HashRouter
export const RouterProvider = RR.RouterProvider
export const useNavigate = RR.useNavigate
export const useParams = RR.useParams
export const useSearchParams = RR.useSearchParams
export const useLocation = RR.useLocation
export const useMatch = RR.useMatch
export const useResolvedPath = RR.useResolvedPath
export const useNavigationType = RR.useNavigationType
export const generatePath = RR.generatePath
export const matchPath = RR.matchPath
export const matchRoutes = RR.matchRoutes
export const createBrowserRouter = RR.createBrowserRouter
export const createHashRouter = RR.createHashRouter
export const createMemoryRouter = RR.createMemoryRouter

export default RR
