/**
 * Type entry for the `react-i18next` shim
 * (`stubs/extensions/react-i18next-shim.mjs.stub`). `npm run build:types`
 * bundles it into `react-i18next-shim.d.mts.stub`: the names the shim
 * exports, the types of the library, and the host's module namespace as the
 * default export.
 */
import * as ReactI18next from 'react-i18next'

export { useTranslation, Trans, I18nextProvider, withTranslation, initReactI18next } from 'react-i18next'

// Every type of the library (its interfaces and type aliases), type-only: an
// import of a type is erased from the build, so it needs no shim export, and
// the consumer's tsconfig sends the specifier here instead of to
// `node_modules`.
export type {
  ErrorArgs,
  ErrorCode,
  FallbackNs,
  I18nextProviderProps,
  IcuTransComponent,
  IcuTransContentDeclaration,
  IcuTransProps,
  IcuTransWithoutContextComponent,
  IcuTransWithoutContextProps,
  ReportNamespaces,
  TranslationProps,
  TransProps,
  TransSelectorProps,
  UseTranslationOptions,
  UseTranslationResponse,
  WithTranslation,
  WithTranslationProps,
} from 'react-i18next'

export default ReactI18next
