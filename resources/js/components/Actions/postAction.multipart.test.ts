import { describe, it, expect, vi, beforeEach } from 'vitest'

/*
 * v2.17.0: an action run whose fields hold a file posts multipart, with the
 * encoding of the resource forms, and stays JSON otherwise.
 */

const post = vi.fn()
const upload = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, post: (...a: unknown[]) => post(...a), upload: (...a: unknown[]) => upload(...a) } }
})

import { postAction } from './actionRequest'

beforeEach(() => { post.mockReset(); upload.mockReset() })

describe('postAction', () => {
  it('posts JSON when no field holds a file', () => {
    postAction('/api/resources/items/actions/rename', { resources: [1], fields: { title: 'x' } })

    expect(upload).not.toHaveBeenCalled()
    expect(post).toHaveBeenCalledWith('/api/resources/items/actions/rename', { resources: [1], fields: { title: 'x' }, dryRun: false })
  })

  it('posts multipart when a field holds a file: fields under fields[...], records as resources[i]', () => {
    const file = new File(['hello'], 'guide.txt', { type: 'text/plain' })
    postAction('/api/resources/items/actions/add-version', {
      resources: [4, 9],
      fields: { title: 'English', file, notify: true },
      via: { viaResource: 'items', viaResourceId: 2, viaRelationship: 'versions' },
    })

    expect(post).not.toHaveBeenCalled()
    expect(upload).toHaveBeenCalledWith('POST', '/api/resources/items/actions/add-version', {
      dryRun: false,
      viaResource: 'items', viaResourceId: 2, viaRelationship: 'versions',
      'resources[0]': 4, 'resources[1]': 9,
      'fields[title]': 'English', 'fields[file]': file, 'fields[notify]': true,
    })
  })
})
