import { describe, it, expect } from 'vitest'
import { updatePayload } from './updatePayload'

/*
 * What the update page and the update drawer submit. Both reduced every
 * `{ id, title }` object to its id, which is right for a BelongsTo but also
 * caught a MorphTo value (`{ type, id, title, resourceType }`): the server
 * cannot place a bare id, ignored it, and a MorphTo changed on an edit form
 * was never saved.
 */

describe('updatePayload', () => {
  it('reduces a BelongsTo value to its id', () => {
    expect(updatePayload({ author: { id: 7, title: 'Ana' } })).toEqual({ author: 7 })
  })

  it('keeps a MorphTo target map, as loaded and as picked', () => {
    const loaded = { type: 'App\\Models\\Post', id: 3, title: 'Hello', resourceType: 'posts' }
    const picked = { resourceType: 'videos', id: '01HZX3ULIDLIKEKEY', title: 'Clip' }

    expect(updatePayload({ commentable: loaded, subject: picked })).toEqual({ commentable: loaded, subject: picked })
  })

  it('leaves out a file value that is still the stored one and keeps a new upload', () => {
    const upload = new File(['x'], 'logo.png', { type: 'image/png' })

    expect(updatePayload({ logo: { url: '/storage/logo.png', path: 'logo.png' }, cover: upload })).toEqual({ cover: upload })
  })

  it('passes nulls, scalars, lists and plain maps through unchanged', () => {
    const values = {
      name: 'Acme',
      published: false,
      cleared: null,
      states: ['a', 'b'],
      metadata: [{ key: 'size', value: '50-200' }],
      effects: { blur: true },
    }

    expect(updatePayload(values)).toEqual(values)
  })
})
