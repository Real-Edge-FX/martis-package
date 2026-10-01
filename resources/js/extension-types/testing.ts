/**
 * The type entry of `dist/testing/testing.d.mts`, the declarations of the
 * Martis test runtime (`@martis/testing` in a consumer's tsconfig and Vitest
 * config, v2.3.0). `react` and `@tanstack/react-query` stay imports, which
 * the consumer's tsconfig resolves (the latter to the shim's declarations),
 * so a QueryClient a test creates is the type the provider takes.
 */
export { MartisTestProvider, defaultTestUser } from '@/testing/MartisTestProvider'
export type { MartisTestProviderProps } from '@/testing/MartisTestProvider'
