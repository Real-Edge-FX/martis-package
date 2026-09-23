/**
 * Type entry for the `react-dom` shim
 * (`stubs/extensions/react-dom-shim.mjs.stub`). `npm run build:types`
 * bundles it into `react-dom-shim.d.mts.stub`: the names the shim exports
 * (`createPortal`, the part of `react-dom` the runtime carries), and an
 * object holding them as the default export. The host's declaration of
 * `createPortal` is inlined, since the consumer's tsconfig sends
 * `react-dom` to these declarations.
 */
import { createPortal } from 'react-dom'

export { createPortal }

export default { createPortal }
