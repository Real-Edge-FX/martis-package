import { describe, it, expect } from 'vitest'
import { createPayload } from './createPayload'

/*
 * What the create page, the create drawer and the inline create modal submit.
 * A create launched from a relationship panel pre-fills the BelongsTo back to
 * the parent as `{ id, title }`, and the form posted that object as it was: a
 * consumer `Rule::exists(...)` on the field received an array.
 */

describe('createPayload', () => {
  it('reduces a BelongsTo value to its id', () => {
    expect(createPayload({ contact_id: { id: '13', title: 'Ana Silva' } })).toEqual({ contact_id: '13' })
    expect(createPayload({ contact_id: { id: 13, title: '' } })).toEqual({ contact_id: 13 })
  })

  it('keeps a MorphTo target map, as copied and as picked', () => {
    const copied = { type: 'App\\Models\\Post', id: 3, title: 'Hello', resourceType: 'posts' }
    const picked = { resourceType: 'videos', id: '01HZX3ULIDLIKEKEY', title: 'Clip' }

    expect(createPayload({ commentable: copied, subject: picked })).toEqual({ commentable: copied, subject: picked })
  })

  it('keeps a new upload and a file value with a url: a new record has no stored file to keep', () => {
    const upload = new File(['x'], 'logo.png', { type: 'image/png' })
    const copied = { url: '/storage/logo.png', path: 'logo.png' }

    expect(createPayload({ cover: upload, logo: copied })).toEqual({ cover: upload, logo: copied })
  })

  it('passes nulls, scalars, lists and plain maps through unchanged', () => {
    const values = {
      name: 'Acme',
      published: false,
      cleared: null,
      states: ['a', 'b'],
      metadata: [{ key: 'size', value: '50-200' }],
      effects: { blur: true },
      related: [{ id: 1, title: 'A' }],
    }

    expect(createPayload(values)).toEqual(values)
  })
})
