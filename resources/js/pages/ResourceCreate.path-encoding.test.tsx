import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { apiSegments, resolveApiPath } from '@/test-support/apiPaths'

/*
 * F016: the create page reads `viaResource`, `viaResourceId`,
 * `viaRelationship` and `viaRelationshipType` from the link's query string
 * (`searchParams.get()` decodes them) and, with the first three present,
 * posts the form to `/api/resources/{viaResource}/{viaResourceId}/{type}/{relationship}`.
 * Raw, a crafted link turned the click on Create into a credentialed POST to
 * any panel endpoint (`viaRelationshipType=../../../users/5/actions/x?`).
 * Every value is now one encoded segment, and the type has to be one of the
 * endpoints that store a nested record. The read-only siblings (the parent
 * lookup, the replicate prefill) encode theirs too.
 */

const apiGetMock = vi.fn()
const apiPostMock = vi.fn(() => new Promise(() => {}))

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: (...args: unknown[]) => apiPostMock(...(args as [])),
      upload: vi.fn(() => new Promise(() => {})),
    },
  }
})

import { ResourceCreatePage } from '@/pages/ResourceCreate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function field(attribute: string, label: string, type: string): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
  } as unknown as FieldDefinition
}

const comments = {
  uriKey: 'comments',
  label: 'Comments',
  singularLabel: 'Comment',
  fields: [],
  fieldsForCreate: [field('body', 'Body', 'text')],
  errorDisplay: 'inline',
  confirmUnsavedChanges: false,
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockClear()
  apiGetMock.mockImplementation((path: string) => {
    if (path.endsWith('/schema')) return Promise.resolve({ data: comments })
    return Promise.resolve({ data: { values: {} } })
  })
})

function renderCreate(url: string) {
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/create', element: <ResourceCreatePage /> }],
    { initialEntries: [url] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

async function submitForm() {
  await waitFor(() => expect(document.getElementById('body')).not.toBeNull())
  fireEvent.change(document.getElementById('body')!, { target: { value: 'Nice' } })
  fireEvent.submit(document.querySelector('form')!)
}

describe('ResourceCreatePage — nested create from a crafted link', () => {
  it('keeps every via* value as one segment of the nested endpoint', async () => {
    const url = '/resources/comments/create?'
      + new URLSearchParams({
        viaResource: '../users',
        viaResourceId: '5?email=attacker@example.com',
        viaRelationship: 'comments/../../x',
        viaRelationshipType: 'has-many',
      }).toString()
    renderCreate(url)
    await submitForm()

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    const [path] = apiPostMock.mock.calls[0] as unknown as [string]
    expect(apiSegments(path)).toEqual([
      'resources',
      '..%252Fusers',
      '5%3Femail%3Dattacker%40example.com',
      'has-many',
      'comments%252F..%252F..%252Fx',
    ])
    expect(resolveApiPath(path).search).toBe('')
  })

  it.each([
    '../../../users/5/actions/promote-to-admin?',
    'actions',
    'has-many/../../..',
    'morph-to-many',
    'belongs-to-many',
    '',
  ])('refuses the relationship type %j: the form posts to the resource itself', async (type) => {
    renderCreate('/resources/comments/create?' + new URLSearchParams({
      viaResource: 'posts',
      viaResourceId: '1',
      viaRelationship: 'comments',
      viaRelationshipType: type,
    }).toString())
    await submitForm()

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    const [path] = apiPostMock.mock.calls[0] as unknown as [string]
    expect(path).toBe('/api/resources/comments')
  })

  it.each(['has-many', 'has-one', 'morph-many', 'morph-one'])('still posts to the %s endpoint', async (type) => {
    renderCreate('/resources/comments/create?' + new URLSearchParams({
      viaResource: 'posts',
      viaResourceId: '1',
      viaRelationship: 'comments',
      viaRelationshipType: type,
    }).toString())
    await submitForm()

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    const [path] = apiPostMock.mock.calls[0] as unknown as [string]
    expect(path).toBe(`/api/resources/posts/1/${type}/comments`)
  })

  it('defaults to has-many when the link names no type', async () => {
    renderCreate('/resources/comments/create?viaResource=posts&viaResourceId=1&viaRelationship=comments')
    await submitForm()

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    expect((apiPostMock.mock.calls[0] as unknown as [string])[0]).toBe('/api/resources/posts/1/has-many/comments')
  })

  it('encodes the parent lookup and the replicate prefill', async () => {
    renderCreate('/resources/comments/create?' + new URLSearchParams({
      viaResource: '../users',
      viaResourceId: '5?x=1',
      viaRelationship: 'comments',
      fromResourceId: '../users/9?y=2',
    }).toString())

    await waitFor(() => expect(apiGetMock.mock.calls.some(([p]) => String(p).endsWith('/replicate'))).toBe(true))

    const paths = apiGetMock.mock.calls.map(([p]) => String(p))
    const replicate = paths.find((p) => p.endsWith('/replicate'))!
    expect(apiSegments(replicate)).toEqual(['resources', 'comments', '..%252Fusers%252F9%3Fy%3D2', 'replicate'])
    expect(resolveApiPath(replicate).search).toBe('')
  })

  it('encodes the parent lookup of a nested create', async () => {
    const parentField = {
      ...field('post_id', 'Post', 'belongs_to'),
      relatedResource: '../users',
    } as unknown as FieldDefinition
    apiGetMock.mockImplementation((path: string) => {
      if (path.endsWith('/schema')) return Promise.resolve({ data: { ...comments, fieldsForCreate: [field('body', 'Body', 'text'), parentField] } })
      return Promise.resolve({ data: { id: 1, _title: 'Parent' } })
    })
    renderCreate('/resources/comments/create?' + new URLSearchParams({
      viaResource: '../users',
      viaResourceId: '5?x=1',
      viaRelationship: 'comments',
    }).toString())

    await waitFor(() => {
      expect(apiGetMock.mock.calls.some(([p]) => !String(p).endsWith('/schema'))).toBe(true)
    })
    const lookup = apiGetMock.mock.calls.map(([p]) => String(p)).find((p) => !p.endsWith('/schema'))!
    expect(apiSegments(lookup)).toEqual(['resources', '..%252Fusers', '5%3Fx%3D1'])
  })
})
