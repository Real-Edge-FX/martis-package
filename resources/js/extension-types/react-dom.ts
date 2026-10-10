/**
 * Type entry for the `react-dom` shim
 * (`stubs/extensions/react-dom-shim.mjs.stub`). `npm run build:types`
 * bundles it into `react-dom-shim.d.mts.stub`: the names the shim exports
 * (`createPortal`, `flushSync`, `unstable_batchedUpdates` and `version`, the
 * public ReactDOM 18 API the host serves as `window.Martis.reactDom`), the
 * types of the library, and an object holding the names as the default
 * export. The host's declarations are inlined, since the consumer's tsconfig
 * sends `react-dom` to these declarations.
 *
 * The legacy root APIs (`render`, `hydrate`, `findDOMNode`,
 * `unmountComponentAtNode`, `unstable_renderSubtreeIntoContainer`) are left
 * out of the values: React 19 removed them. Their types stay, as every type
 * of the library does.
 */
import { createPortal, flushSync, unstable_batchedUpdates, version } from 'react-dom'

export { createPortal, flushSync, unstable_batchedUpdates, version }

// Every type of the library (its interfaces and type aliases), type-only: an
// import of a type is erased from the build, so it needs no shim export, and
// the consumer's tsconfig sends the specifier here instead of to
// `node_modules`.
export type {
  Container,
  Renderer,
} from 'react-dom'

export default { createPortal, flushSync, unstable_batchedUpdates, version }
