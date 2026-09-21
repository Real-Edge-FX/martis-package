import { describe, it, expect } from 'vitest'
import { buildFormData } from './api'

/*
 * The multipart request path (any form that uploads a file) must carry
 * every non-file value in a shape the server reads back as the JSON path
 * would send it. It used to `String()` everything, so a Repeater's rows
 * became "[object Object],[object Object]", a MultiSelect's ids "12,15",
 * a BooleanGroup's map "[object Object]" and an unchecked Boolean "false"
 * (true, once PHP casts it).
 */

function entries(fd: FormData): Record<string, unknown[]> {
  const out: Record<string, unknown[]> = {}
  fd.forEach((value, key) => {
    ;(out[key] ??= []).push(value)
  })
  return out
}

describe('buildFormData', () => {
  it('JSON-encodes arrays and plain objects', () => {
    const fd = buildFormData({
      home_sections: [{ id: 'a', type: 'hero', fields: { enabled: true } }],
      featured_property_ids: [12, 15],
      effects: { blur: true, grain: false },
      empty_list: [],
      empty_map: {},
    })

    expect(entries(fd)).toEqual({
      home_sections: ['[{"id":"a","type":"hero","fields":{"enabled":true}}]'],
      featured_property_ids: ['[12,15]'],
      effects: ['{"blur":true,"grain":false}'],
      empty_list: ['[]'],
      empty_map: ['{}'],
    })
  })

  it('sends booleans as 1 / 0', () => {
    expect(entries(buildFormData({ published: true, archived: false }))).toEqual({
      published: ['1'],
      archived: ['0'],
    })
  })

  it('keeps scalars, empties null/undefined and appends the method override', () => {
    const fd = buildFormData({ title: 'Hello', count: 3, cleared: null, missing: undefined }, 'PUT')

    expect(entries(fd)).toEqual({
      _method: ['PUT'],
      title: ['Hello'],
      count: ['3'],
      cleared: [''],
      missing: [''],
    })
  })

  it('appends a File as is and splits a multiple-file value into uploads and kept paths', () => {
    const logo = new File(['x'], 'logo.png', { type: 'image/png' })
    const extra = new File(['y'], 'extra.pdf', { type: 'application/pdf' })
    const fd = buildFormData({
      logo,
      documents: {
        __multiple: true,
        items: [
          { id: '1', existing: { path: 'docs/a.pdf', url: '/a', name: 'a.pdf' } },
          { id: '2', file: extra },
        ],
      },
      gallery: { __multiple: true, items: [] },
    })

    const got = entries(fd)
    expect(got.logo[0]).toBe(logo)
    expect(got['documents_keep[]']).toEqual(['docs/a.pdf'])
    expect(got['documents[0]'][0]).toBe(extra)
    expect(got.gallery_keep).toEqual([''])
  })
})
