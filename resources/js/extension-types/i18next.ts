/**
 * The type entry of `.shims/i18next.d.mts` (v2.3.0): i18next's own types,
 * which the react-i18next and runtime declarations import instead of each
 * inlining a copy. The consumer's tsconfig sends `i18next` here too, so an
 * instance a test creates with `i18next.createInstance()` is the very type
 * the shim's `I18nextProvider` takes. Types only: nothing imports i18next
 * through a shim at runtime.
 */
export * from 'i18next'
export { default } from 'i18next'
