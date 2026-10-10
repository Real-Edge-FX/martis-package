import { describe, it, expect } from 'vitest'
import { updatePayload } from './updatePayload'
import type { FieldDefinition } from '@/types'

/*
 * What the update page and the update drawer submit. The reduction goes by the
 * field's type: a BelongsTo (`{ id, title }`) is reduced to its id, a MorphTo
 * keeps its target map (the server cannot place a bare id), a stored file is
 * left out, and any other field (a KeyValue, a JSON `Code` field, a custom
 * field) is sent as it is, even when its map has `id`, `title` or `url` keys.
 */

const field = (attribute: string, type: string): FieldDefinition => ({ attribute, label: attribute, type }) as FieldDefinition

const fields = [
  field('author', 'belongs_to'),
  field('commentable', 'morph_to'),
  field('logo', 'file'),
  field('cover', 'image'),
  field('avatar', 'avatar'),
  field('clip', 'audio'),
  field('metadata', 'key_value'),
  field('settings', 'code'),
  field('name', 'text'),
]

describe('updatePayload', () => {
  it('reduces a BelongsTo value to its id', () => {
    expect(updatePayload({ author: { id: 7, title: 'Ana' } }, fields)).toEqual({ author: 7 })
  })

  it('sends the trashed opt-in for a soft-deleted BelongsTo target', () => {
    expect(updatePayload({ author: { id: 7, title: 'Ana', trashed: true } }, fields)).toEqual({
      author: 7,
      author_trashed: true,
    })
  })

  it('keeps a MorphTo target map, as loaded and as picked', () => {
    const loaded = { type: 'App\\Models\\Post', id: 3, title: 'Hello', resourceType: 'posts' }
    const picked = { resourceType: 'videos', id: '01HZX3ULIDLIKEKEY', title: 'Clip' }

    expect(updatePayload({ commentable: loaded }, fields)).toEqual({ commentable: loaded })
    expect(updatePayload({ commentable: picked }, fields)).toEqual({ commentable: picked })
  })

  it('leaves out a file value that is still the stored one and keeps a new upload', () => {
    const upload = new File(['x'], 'logo.png', { type: 'image/png' })
    const stored = { url: '/storage/logo.png', path: 'logo.png' }

    expect(updatePayload({ logo: stored, cover: upload, avatar: stored, clip: stored }, fields)).toEqual({ cover: upload })
  })

  it('keeps a map with id, title or url keys on a field that is not a BelongsTo or a file', () => {
    const idTitle = { id: 'abc', title: 'Mr', other: 1 }
    const withUrl = { url: 'https://example.com', label: 'Home' }

    expect(updatePayload({ metadata: idTitle, settings: withUrl }, fields)).toEqual({ metadata: idTitle, settings: withUrl })
  })

  it('leaves a value whose attribute has no definition untouched', () => {
    const value = { id: 1, title: 'x', url: 'y' }

    expect(updatePayload({ unknown: value }, fields)).toEqual({ unknown: value })
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

    expect(updatePayload(values, fields)).toEqual(values)
  })
})
