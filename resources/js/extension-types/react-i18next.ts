/**
 * Type entry for the `react-i18next` shim
 * (`stubs/extensions/react-i18next-shim.mjs.stub`). `npm run build:types`
 * bundles it into `react-i18next-shim.d.mts.stub`: the names the shim
 * exports, and the host's module namespace as the default export.
 */
import * as ReactI18next from 'react-i18next'

export { useTranslation, Trans, I18nextProvider, withTranslation, initReactI18next } from 'react-i18next'

export default ReactI18next
