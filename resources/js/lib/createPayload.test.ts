import { describe, it, expect } from 'vitest'
import { createPayload } from './createPayload'
import type { FieldDefinition } from '@/types'

/*
 * What the create page, the create drawer and the inline create modal submit.
 * A create launched from a relationship panel pre-fills the BelongsTo back to
 * the parent as `{ id, title }`, and the form posted that object as it was: a
 * consumer `Rule::exists(...)` on the field received an array. The reduction
 * goes by the field's type, never by the shape of the value: a KeyValue or a
 * JSON field may hold a map with `id` and `title` keys of its own.
 */

const field = (attribute: string, type: string): FieldDefinition => ({ attribute, label: attribute, type }) as FieldDefinition

const fields = [
  field('contact_id', 'belongs_to'),
  field('commentable', 'morph_to'),
  field('cover', 'image'),
  field('logo', 'file'),
  field('metadata', 'key_value'),
  field('settings', 'code'),
  field('name', 'text'),
]

describe('createPayload', () => {
  it('reduces a BelongsTo value to its id', () => {
    expect(createPayload({ contact_id: { id: '13', title: 'Ana Silva' } }, fields)).toEqual({ contact_id: '13' })
    expect(createPayload({ contact_id: { id: 13, title: '' } }, fields)).toEqual({ contact_id: 13 })
  })

  it('leaves a BelongsTo scalar alone', () => {
    expect(createPayload({ contact_id: 13, name: 'x' }, fields)).toEqual({ contact_id: 13, name: 'x' })
  })

  it('sends the trashed opt-in for a soft-deleted BelongsTo target', () => {
    expect(createPayload({ contact_id: { id: 13, title: 'Ana', trashed: true } }, fields)).toEqual({
      contact_id: 13,
      contact_id_trashed: true,
    })
    expect(createPayload({ contact_id: { id: 13, title: 'Ana', trashed: false } }, fields)).toEqual({ contact_id: 13 })
  })

  it('keeps a MorphTo target map, as copied and as picked', () => {
    const copied = { type: 'App\\Models\\Post', id: 3, title: 'Hello', resourceType: 'posts' }
    const picked = { resourceType: 'videos', id: '01HZX3ULIDLIKEKEY', title: 'Clip' }

    expect(createPayload({ commentable: copied }, fields)).toEqual({ commentable: copied })
    expect(createPayload({ commentable: picked }, fields)).toEqual({ commentable: picked })
  })

  it('keeps a new upload and a file value with a url: a new record has no stored file to keep', () => {
    const upload = new File(['x'], 'logo.png', { type: 'image/png' })
    const copied = { url: '/storage/logo.png', path: 'logo.png' }

    expect(createPayload({ cover: upload, logo: copied }, fields)).toEqual({ cover: upload, logo: copied })
  })

  it('keeps a map with id and title keys on a field that is not a BelongsTo', () => {
    const map = { id: 'abc', title: 'Mr', other: 1 }

    expect(createPayload({ metadata: map, settings: { id: 1, title: 'x' } }, fields)).toEqual({
      metadata: map,
      settings: { id: 1, title: 'x' },
    })
  })

  it('leaves a value whose attribute has no definition untouched', () => {
    const value = { id: 1, title: 'x' }

    expect(createPayload({ unknown: value }, fields)).toEqual({ unknown: value })
  })

  it('finds a BelongsTo inside a panel, a section and a tab group', () => {
    const tree = [
      { type: 'panel', fields: [field('a_id', 'belongs_to')] },
      { type: 'section', fields: [field('b_id', 'belongs_to')] },
      { type: 'tab_group', tabs: [{ fields: [field('c_id', 'belongs_to')] }] },
    ]

    expect(
      createPayload(
        { a_id: { id: 1, title: 'A' }, b_id: { id: 2, title: 'B' }, c_id: { id: 3, title: 'C' } },
        tree as unknown as FieldDefinition[],
      ),
    ).toEqual({ a_id: 1, b_id: 2, c_id: 3 })
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

    expect(createPayload(values, fields)).toEqual(values)
  })
})
