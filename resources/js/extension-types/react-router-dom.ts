/**
 * Type entry for the `react-router-dom` shim
 * (`stubs/extensions/react-router-dom-shim.mjs.stub`). `npm run build:types`
 * bundles it into `react-router-dom-shim.d.mts.stub`: the names the shim
 * exports, the types of the library, and the whole module as its default
 * export (the shim's default is `window.Martis.runtime.reactRouterDom`, the
 * host's module namespace).
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

// Every type of the library (its interfaces and type aliases), type-only: an
// import of a type is erased from the build, so it needs no shim export, and
// the consumer's tsconfig sends the specifier here instead of to
// `node_modules`. A class or enum the shim does not export (`NavigationType`) is
// not among them: rollup-plugin-dts would declare it as a value, which the
// build does not have. The default export, the host's module, holds it.
export type {
  ActionFunction,
  ActionFunctionArgs,
  AwaitProps,
  Blocker,
  BlockerFunction,
  BrowserRouterProps,
  DataRouteMatch,
  DataRouteObject,
  DataStrategyFunction,
  DataStrategyFunctionArgs,
  DataStrategyMatch,
  DataStrategyResult,
  ErrorResponse,
  Fetcher,
  FetcherFormProps,
  FetcherSubmitFunction,
  FetcherWithComponents,
  FormEncType,
  FormMethod,
  FormProps,
  FutureConfig,
  GetScrollRestorationKeyFunction,
  Hash,
  HashRouterProps,
  HistoryRouterProps,
  IndexRouteObject,
  IndexRouteProps,
  JsonFunction,
  LayoutRouteProps,
  LazyRouteFunction,
  LinkProps,
  LoaderFunction,
  LoaderFunctionArgs,
  Location,
  MemoryRouterProps,
  NavigateFunction,
  NavigateOptions,
  NavigateProps,
  Navigation,
  Navigator,
  NavLinkProps,
  NavLinkRenderProps,
  NonIndexRouteObject,
  OutletProps,
  ParamKeyValuePair,
  ParamParseKey,
  Params,
  PatchRoutesOnNavigationFunction,
  PatchRoutesOnNavigationFunctionArgs,
  Path,
  PathMatch,
  Pathname,
  PathParam,
  PathPattern,
  PathRouteProps,
  RedirectFunction,
  RelativeRoutingType,
  RouteMatch,
  RouteObject,
  RouteProps,
  RouterProps,
  RouterProviderProps,
  RoutesProps,
  ScrollRestorationProps,
  Search,
  SetURLSearchParams,
  ShouldRevalidateFunction,
  ShouldRevalidateFunctionArgs,
  SubmitFunction,
  SubmitOptions,
  To,
  UIMatch,
  URLSearchParamsInit,
  V7_FormMethod,
} from 'react-router-dom'

export default ReactRouterDom
