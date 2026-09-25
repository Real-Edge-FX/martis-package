/**
 * The `react-router-dom` module Martis serves to consumer extensions, as
 * `window.Martis.runtime.reactRouterDom` (the `react-router-dom` shim a
 * consumer build aliases the specifier to re-exports it).
 *
 * Since React Router 7 the library lives in `react-router`, and the
 * `react-router-dom` package is only a re-export of it plus the DOM-aware
 * `RouterProvider` from `react-router/dom` (the one that runs a navigation's
 * state update in `ReactDOM.flushSync` when the navigation asks for it). The
 * SPA imports `react-router` directly, so instead of installing
 * `react-router-dom` for the extension surface this module is that same
 * re-export: every export of `react-router`, with the DOM `RouterProvider`
 * in place of the core one (a local export wins over a star export). An
 * extension compiled against React Router 6 reads the same names off it
 * (`Link`, `useNavigate`, `useParams`, ...).
 */
export * from 'react-router'
export { RouterProvider } from 'react-router/dom'
