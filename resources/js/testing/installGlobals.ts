/**
 * The first module of the test runtime (`dist/testing/testing.mjs`): it runs
 * before the rest of the runtime is evaluated, so `lib/config.ts`, which
 * reads `window.MartisConfig` once when it loads, reads these defaults and
 * keeps this very object (MartisTestProvider's `config` writes into it). A
 * value a setup file put on `window.MartisConfig` before importing the
 * runtime wins.
 */
const target = window as unknown as { MartisConfig?: Record<string, unknown> }

target.MartisConfig = {
  basePath: '/martis',
  locale: 'en',
  auth: {},
  preferences: { enabled: true },
  ...(target.MartisConfig ?? {}),
}

export {}
