import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/lib/config', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/config')>()
  return {
    ...actual,
    config: {
      ...actual.config,
      resourceRecordUrls: { projects: '/tools/pk?id={id}' },
    },
  }
})

import { recordHref } from './recordHref'

describe('recordHref', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('interpolates the per-resource template when one is configured', () => {
    expect(recordHref('projects', 7)).toBe('/tools/pk?id=7')
  })

  it('encodes the id when interpolating a template', () => {
    expect(recordHref('projects', 'a b')).toBe('/tools/pk?id=a%20b')
  })

  it('falls back to the default detail path when no template is configured', () => {
    expect(recordHref('users', 5)).toBe('/resources/users/5')
  })
})
