// Build-time globals injected by `vite.config.ts` `define`. Each
// constant is replaced with its serialised value at build time.

declare const __MARTIS_VERSION__: string

// The React version the panel runs (the package's own), injected by
// `vite.testing.config.ts` only: the test runtime checks a consumer's
// test run against its major (resources/js/testing/checkReactMajor.ts).
declare const __MARTIS_REACT_VERSION__: string
