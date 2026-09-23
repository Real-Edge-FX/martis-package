/**
 * Type entry for the `react-router-dom` shim
 * (`stubs/extensions/react-router-dom-shim.mjs.stub`). `npm run build:types`
 * bundles it into `react-router-dom-shim.d.mts.stub`: the names the shim
 * exports, and the whole module as its default export (the shim's default
 * is `window.Martis.runtime.reactRouterDom`, the host's module namespace).
 */
import * as ReactRouterDom from 'react-router-dom'

export {
  Link,
  NavLink,
  Outlet,
  Navigate,
  Route,
  Routes,
  BrowserRouter,
  HashRouter,
  RouterProvider,
  useNavigate,
  useParams,
  useSearchParams,
  useLocation,
  useMatch,
  useResolvedPath,
  useNavigationType,
  generatePath,
  matchPath,
  matchRoutes,
  createBrowserRouter,
  createHashRouter,
  createMemoryRouter,
} from 'react-router-dom'

export default ReactRouterDom
