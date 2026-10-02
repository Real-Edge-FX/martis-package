import { describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/config', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/config')>()
  return { ...actual, config: { ...actual.config, resourceRecordUrls: { projects: '/tools/pk?id={id}' } } }
})

import { recordHref } from './recordHref'

/*
 * F035 root cause: `config.resourceRecordUrls?.[uriKey]` also finds what every
 * object inherits. A resource key from a route param or a related-record
 * payload spelled `constructor` read `Object` as a link template and threw.
 */
describe('recordHref with a resource key spelled like an inherited member', () => {
  it.each(['constructor', 'toString', 'valueOf', 'hasOwnProperty'])('links %s through the default detail path', (key) => {
    expect(recordHref(key, 5)).toBe(`/resources/${key}/5`)
  })

  it('still interpolates the template of a resource that has one', () => {
    expect(recordHref('projects', 7)).toBe('/tools/pk?id=7')
  })
})
