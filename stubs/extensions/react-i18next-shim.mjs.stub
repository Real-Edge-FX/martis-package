/**
 * `react-i18next` shim (v1.10.0+).
 *
 * Re-exports from `window.Martis.runtime.reactI18next`. Consumers'
 * vite alias maps bare `react-i18next` here so override stubs that
 * use `useTranslation()` work without the consumer running
 * `npm install react-i18next`.
 */

const I =
  typeof window !== 'undefined' &&
  window.Martis &&
  window.Martis.runtime &&
  window.Martis.runtime.reactI18next
    ? window.Martis.runtime.reactI18next
    : null

if (I === null) {
  throw new Error(
    '[react-i18next-shim] window.Martis.runtime.reactI18next not available — upgrade Martis to v1.10+ or install react-i18next directly.',
  )
}

export const useTranslation = I.useTranslation
export const Trans = I.Trans
export const I18nextProvider = I.I18nextProvider
export const withTranslation = I.withTranslation
export const initReactI18next = I.initReactI18next

export default I
