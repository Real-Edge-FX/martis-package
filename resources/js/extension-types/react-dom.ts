/**
 * Type entry for the `react-dom` shim
 * (`stubs/extensions/react-dom-shim.mjs.stub`). `npm run build:types`
 * bundles it into `react-dom-shim.d.mts.stub`: the names the shim exports
 * (`createPortal` and `flushSync`, the part of `react-dom` the runtime
 * carries), the types
 * of the library, and an object holding the names as the default export.
 * The host's declarations are inlined, since the consumer's tsconfig sends
 * `react-dom` to these declarations.
 */
import { createPortal, flushSync } from 'react-dom'

export { createPortal, flushSync }

// Every type of the library (its interfaces and type aliases), type-only: an
// import of a type is erased from the build, so it needs no shim export, and
// the consumer's tsconfig sends the specifier here instead of to
// `node_modules`.
export type {
  Container,
  Renderer,
} from 'react-dom'

export default { createPortal, flushSync }
