import { describe, it, expect } from 'vitest'
import { createPayload } from './createPayload'

describe('createPayload', () => {
  it('reduces a BelongsTo value to its id', () => {
    expect(createPayload({ contact_id: { id: '13', title: 'Comprador Demo' } })).toEqual({ contact_id: '13' })
  })

  it('keeps a MorphTo target map', () => {
    const picked = { resourceType: 'videos', id: 3, title: 'Clip' }

    expect(createPayload({ subject: picked })).toEqual({ subject: picked })
  })

  it('keeps a file value that carries a url, unlike the update payload', () => {
    const file = { url: '/storage/logo.png', path: 'logo.png' }
    const upload = new File(['x'], 'logo.png', { type: 'image/png' })

    expect(createPayload({ logo: file, cover: upload })).toEqual({ logo: file, cover: upload })
  })

  it('passes nulls, scalars, lists and plain maps through unchanged', () => {
    const values = { name: 'Acme', cleared: null, states: ['a'], rows: [{ id: 1, title: 'kept' }], effects: { blur: true } }

    expect(createPayload(values)).toEqual(values)
  })
})
