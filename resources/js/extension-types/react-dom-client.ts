/**
 * Type entry for the `react-dom/client` shim
 * (`stubs/extensions/react-dom-client-shim.mjs.stub`, v2.10.0).
 * `npm run build:types` bundles it into `react-dom-client-shim.d.mts.stub`:
 * the names the shim exports (`createRoot` and `hydrateRoot`, served as
 * `window.Martis.reactDomClient`), the types of the library, and an object
 * holding the names as the default export. The host's declarations are
 * inlined, since the consumer's tsconfig sends `react-dom/client` to these
 * declarations.
 */
import { createRoot, hydrateRoot } from 'react-dom/client'

export { createRoot, hydrateRoot }

// Every type of the library, type-only (see the `react-dom` entry).
export type {
  Container,
  DO_NOT_USE_OR_YOU_WILL_BE_FIRED_EXPERIMENTAL_CREATE_ROOT_CONTAINERS,
  ErrorInfo,
  HydrationOptions,
  Root,
  RootOptions,
} from 'react-dom/client'

export default { createRoot, hydrateRoot }
