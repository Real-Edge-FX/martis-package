import { describe, expect, it } from 'vitest'
import { martisRuntime } from '@/lib/martisRuntime'
import { apiPath, pathSegment, routePath, withQuery } from '@/lib/apiPath'
import { ApiError } from '@/lib/api'
import shim from '../../stubs/extensions/runtime-shim.mjs.stub?raw'

/*
 * The docs tell an extension author to build an API path with `apiPath` /
 * `pathSegment` (not `encodeURIComponent()`, which leaves `%2F` for Laravel to
 * decode into a route split), so `@martis/runtime` serves them: the same
 * functions the panel's own pages use.
 */
describe('@martis/runtime path builders', () => {
  it('serves the panel own helpers', () => {
    expect(martisRuntime.apiPath).toBe(apiPath)
    expect(martisRuntime.pathSegment).toBe(pathSegment)
    expect(martisRuntime.routePath).toBe(routePath)
    expect(martisRuntime.withQuery).toBe(withQuery)
  })

  it('keeps a slash out of the route the way the docs promise', () => {
    expect(martisRuntime.apiPath`/api/findings/${'5/force'}`).toBe('/api/findings/5%252Fforce')
    expect(encodeURIComponent('5/force')).toBe('5%2Fforce')
  })

  it('refuses a value that holds a literal %2F with the ApiError the runtime serves', () => {
    expect(() => martisRuntime.pathSegment('a%2Fb')).toThrow(martisRuntime.ApiError)
    expect(martisRuntime.ApiError).toBe(ApiError)
  })

  it('is exported by name from the shim a consumer build resolves @martis/runtime to', () => {
    for (const name of ['apiPath', 'pathSegment', 'routePath', 'withQuery']) {
      expect(shim).toContain(`export const ${name} = R.${name}`)
    }
  })
})
