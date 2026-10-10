import { createPortal, flushSync, unstable_batchedUpdates, version } from 'react-dom'
import { createRoot, hydrateRoot } from 'react-dom/client'

/**
 * The public ReactDOM 18 API the consumer-extension `react-dom` and
 * `react-dom/client` shims re-export, as `window.Martis.reactDom` and
 * `window.Martis.reactDomClient` (v2.10.0): what libraries import
 * (`@dnd-kit/core` imports `unstable_batchedUpdates`, `@tanstack/react-virtual`
 * `flushSync`, a portal library `createPortal`, a micro-frontend `createRoot`)
 * and React 19 keeps.
 *
 * The legacy root APIs are left out on purpose (`render`, `hydrate`,
 * `findDOMNode`, `unmountComponentAtNode`, `unstable_renderSubtreeIntoContainer`):
 * React 18 deprecated them and React 19 removed them, so exposing them would
 * tie extensions to APIs the host drops.
 */
export const reactDomHandle = { createPortal, flushSync, unstable_batchedUpdates, version }

export const reactDomClientHandle = { createRoot, hydrateRoot }
