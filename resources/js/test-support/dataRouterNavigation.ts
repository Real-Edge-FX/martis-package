import { afterAll, beforeAll, vi } from 'vitest'

/**
 * Let the calling test file navigate a data router (`createMemoryRouter`)
 * under jsdom.
 *
 * Every data-router navigation builds a `Request` that carries an
 * AbortSignal. Vitest's jsdom environment puts jsdom's AbortController and
 * AbortSignal on the global object, but Node's `Request` only accepts the
 * native AbortSignal it captured when it loaded ("Expected signal to be an
 * instance of AbortSignal"), so `router.navigate()` rejects and the location
 * never changes. While the file runs, the request is built without the
 * signal. The router only uses it to abort loaders and actions, which these
 * tests do not declare.
 */
export function allowDataRouterNavigation(): void {
  beforeAll(() => {
    const NodeRequest = globalThis.Request

    vi.stubGlobal(
      'Request',
      class extends NodeRequest {
        constructor(input: RequestInfo | URL, init?: RequestInit) {
          super(input, init?.signal ? { ...init, signal: undefined } : init)
        }
      },
    )
  })

  afterAll(() => {
    vi.unstubAllGlobals()
  })
}
