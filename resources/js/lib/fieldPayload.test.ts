import { describe, it, expect } from 'vitest'
import { createPayload } from './createPayload'
import { updatePayload } from './updatePayload'

/*
 * Both payload helpers decide per attribute by the field type (v1.39.7), not
 * by the shape of the value: a KeyValue map, a JSON object or a custom
 * field's value may carry `id`, `title` or `url` keys.
 */

const fields = [
  { attribute: 'author', type: 'belongs_to' },
  { attribute: 'commentable', type: 'morph_to' },
  { attribute: 'logo', type: 'image' },
  { attribute: 'attachment', type: 'file' },
  { attribute: 'meta', type: 'key_value' },
  { attribute: 'settings', type: 'code' },
  { attribute: 'link', type: 'text' },
  { attribute: 'custom', type: 'my_custom_field' },
]

const keyValueMap = { id: 'abc', title: 'Hello', size: '10' }

describe.each([
  ['createPayload', createPayload],
  ['updatePayload', updatePayload],
])('%s', (_name, build) => {
  it('reduces a BelongsTo value to its id', () => {
    expect(build({ author: { id: 7, title: 'Ana' } }, fields)).toEqual({ author: 7 })
  })

  it('keeps a MorphTo target map, as loaded and as picked', () => {
    const loaded = { type: 'App\\Models\\Post', id: 3, title: 'Hello', resourceType: 'posts' }
    const picked = { resourceType: 'videos', id: 'ULID', title: 'Clip' }

    expect(build({ commentable: loaded }, fields)).toEqual({ commentable: loaded })
    expect(build({ commentable: picked }, fields)).toEqual({ commentable: picked })
  })

  it('keeps a KeyValue map that has id and title keys', () => {
    expect(build({ meta: keyValueMap }, fields)).toEqual({ meta: keyValueMap })
  })

  it('keeps a JSON object and a custom field value that have id and title keys', () => {
    const values = { settings: { id: 1, title: 'x' }, custom: { id: 2, title: 'y' } }

    expect(build(values, fields)).toEqual(values)
  })

  it('keeps a { url } map on a field that is not a file field', () => {
    const values = { link: { url: 'https://a.test', label: 'A' }, meta: { url: 'x' } }

    expect(build(values, fields)).toEqual(values)
  })

  it('passes a new upload, nulls, scalars, lists and attributes without a definition through', () => {
    const upload = new File(['x'], 'logo.png', { type: 'image/png' })
    const values = { logo: upload, name: 'Acme', cleared: null, states: ['a'], rows: [{ id: 1, title: 'k' }], ghost: { id: 1, title: 'g' } }

    expect(build(values, fields)).toEqual(values)
  })

  it('finds the definitions inside panels, sections and tab groups', () => {
    const nested = [
      { type: 'panel', fields: [{ attribute: 'author', type: 'belongs_to' }] },
      { type: 'tab_group', tabs: [{ fields: [{ type: 'section', fields: [{ attribute: 'meta', type: 'key_value' }] }] }] },
    ]

    expect(build({ author: { id: 1, title: 'A' }, meta: keyValueMap }, nested)).toEqual({ author: 1, meta: keyValueMap })
  })
})

describe('the stored file value', () => {
  const stored = { url: '/storage/logo.png', path: 'logo.png' }

  it('is kept on create (a replicated record carries its files)', () => {
    expect(createPayload({ logo: stored, attachment: stored }, fields)).toEqual({ logo: stored, attachment: stored })
  })

  it('is left out on update, so the server keeps the file', () => {
    const upload = new File(['x'], 'a.pdf')

    expect(updatePayload({ logo: stored, attachment: upload }, fields)).toEqual({ attachment: upload })
  })
})
