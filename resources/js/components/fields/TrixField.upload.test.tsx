import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { TrixFieldInput } from './TrixField'

/*
 * A Trix attachment goes to `/api/attachments/upload` naming what the server
 * authorises it by, as the form it comes from does: the resource, the field
 * and (editing) the record, plus the Repeater row when the field is inside
 * one. The request never names a disk: the server takes it from the field.
 * An editor outside a resource form (an Action's, a Tool's or a pivot's
 * fields, which the endpoint cannot resolve) accepts no attachment.
 *
 * Trix itself stays out of jsdom: the tests dispatch the `trix-attachment-add`
 * event the editor fires on a dropped or picked file.
 */

vi.mock('trix', () => ({}))

const withFilesField = {
  attribute: 'body', label: 'Body', type: 'trix', withFiles: 'public',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [],
} as unknown as FieldDefinition

const plainField = { ...withFilesField, withFiles: undefined } as unknown as FieldDefinition

type FakeAttachment = {
  file: File
  remove: ReturnType<typeof vi.fn>
  setUploadProgress: ReturnType<typeof vi.fn>
  setAttributes: ReturnType<typeof vi.fn>
}

function attach(container: ParentNode): FakeAttachment {
  const editor = container.querySelector('trix-editor')
  if (!editor) throw new Error('no Trix editor rendered')
  const attachment: FakeAttachment = {
    file: new File(['x'], 'photo.png', { type: 'image/png' }),
    remove: vi.fn(),
    setUploadProgress: vi.fn(),
    setAttributes: vi.fn(),
  }
  const event = new Event('trix-attachment-add')
  Object.assign(event, { attachment })
  fireEvent(editor, event)
  return attachment
}

const fetchMock = vi.fn()

beforeEach(() => {
  fetchMock.mockReset()
  fetchMock.mockResolvedValue({ ok: true, json: async () => ({ url: '/storage/martis-attachments/a.png', href: '/storage/martis-attachments/a.png' }) })
  vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

function requestOf(call = 0): { url: URL; body: FormData } {
  const [path, init] = fetchMock.mock.calls[call] as [string, { body: FormData }]
  return { url: new URL(path, 'http://localhost'), body: init.body }
}

describe('TrixFieldInput attachment upload', () => {
  it('names the resource and the field on a create form', async () => {
    const { container } = render(<TrixFieldInput field={withFilesField} value="" onChange={() => {}} resourceKey="posts" />)

    const attachment = attach(container)

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const { url, body } = requestOf()
    expect(url.pathname.endsWith('/api/attachments/upload')).toBe(true)
    expect(Object.fromEntries(url.searchParams)).toEqual({ resource: 'posts', field: 'body' })
    expect((body.get('file') as File).name).toBe('photo.png')
    expect(body.has('disk')).toBe(false)
    await vi.waitFor(() => expect(attachment.setAttributes).toHaveBeenCalledWith({ url: '/storage/martis-attachments/a.png', href: '/storage/martis-attachments/a.png' }))
  })

  it('names the record on an edit form', () => {
    const { container } = render(<TrixFieldInput field={withFilesField} value="" onChange={() => {}} resourceKey="posts" recordId={42} />)

    attach(container)

    expect(Object.fromEntries(requestOf().url.searchParams)).toEqual({ resource: 'posts', field: 'body', id: '42' })
  })

  it('names the Repeater row the field is in', () => {
    const { container } = render(
      <TrixFieldInput field={withFilesField} value="" onChange={() => {}} resourceKey="posts" repeaterRow={{ repeater: 'blocks', repeatable: 'text-block' }} />,
    )

    attach(container)

    expect(Object.fromEntries(requestOf().url.searchParams)).toEqual({ resource: 'posts', field: 'body', repeater: 'blocks', repeatable: 'text-block' })
  })

  it('names the record the latest render edits, without rebuilding the editor', () => {
    const { container, rerender } = render(<TrixFieldInput field={withFilesField} value="" onChange={() => {}} resourceKey="posts" recordId={1} />)
    const editor = container.querySelector('trix-editor')

    rerender(<TrixFieldInput field={withFilesField} value="" onChange={() => {}} resourceKey="posts" recordId={2} />)
    expect(container.querySelector('trix-editor')).toBe(editor)

    attach(container)

    expect(requestOf().url.searchParams.get('id')).toBe('2')
  })

  it('removes the attachment when the upload is refused', async () => {
    fetchMock.mockResolvedValue({ ok: false, status: 403, json: async () => ({}) })
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { container } = render(<TrixFieldInput field={withFilesField} value="" onChange={() => {}} resourceKey="posts" />)

    const attachment = attach(container)

    await vi.waitFor(() => expect(attachment.remove).toHaveBeenCalled())
    expect(attachment.setAttributes).not.toHaveBeenCalled()
  })

  it('does not upload from a field that declares no withFiles()', () => {
    const { container } = render(<TrixFieldInput field={plainField} value="" onChange={() => {}} resourceKey="posts" />)

    const attachment = attach(container)

    expect(fetchMock).not.toHaveBeenCalled()
    expect(attachment.remove).toHaveBeenCalled()
  })

  it.each([
    ['an Action field', { actionEndpoint: '/api/resources/posts/actions/publish' }],
    ['an Action field of a resource page', { resourceKey: 'posts', actionEndpoint: '/api/resources/posts/actions/publish' }],
    ['a pivot field', { resourceKey: 'posts', pivotEndpoint: '/api/resources/posts/1/belongs-to-many/tags/pivot-fields' }],
    ['a Tool field', { toolKey: 'settings' }],
    ['a field with no resource', {}],
  ])('does not upload from %s, which the endpoint cannot authorise', (_label, props) => {
    const { container } = render(<TrixFieldInput field={withFilesField} value="" onChange={() => {}} {...props} />)

    const attachment = attach(container)

    expect(fetchMock).not.toHaveBeenCalled()
    expect(attachment.remove).toHaveBeenCalled()
  })
})
