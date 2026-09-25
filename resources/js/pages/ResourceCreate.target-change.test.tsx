import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act, render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * The router keeps the create page's instance when the URL moves to another
 * resource's create page, to another parent's nested create, or to another
 * record to replicate (a redirectAfterCreate(), a menu link, back/forward).
 * The page must then start from the target in the URL, never from what was
 * typed or prefilled for the previous one, even the input the user just
 * agreed to discard.
 */

const apiGetMock = vi.fn()
const apiPostMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: (...args: unknown[]) => apiPostMock(...args),
    },
  }
})

import { ResourceCreatePage } from '@/pages/ResourceCreate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()
allowDataRouterNavigation()

function field(attribute: string, label: string, extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute,
    label,
    type: 'text',
    nullable: false,
    readonly: false,
    required: false,
    sortable: false,
    searchable: false,
    showOnIndex: false,
    showOnDetail: true,
    showOnForms: true,
    rules: [],
    reserved: [],
    ...extra,
  } as unknown as FieldDefinition
}

function schema(uriKey: string, singularLabel: string, fieldsForCreate: FieldDefinition[], confirmUnsavedChanges: boolean): ResourceSchema {
  return {
    uriKey,
    label: `${singularLabel}s`,
    singularLabel,
    fields: [],
    fieldsForCreate,
    errorDisplay: 'inline',
    confirmUnsavedChanges,
    messages: {},
  } as unknown as ResourceSchema
}

const SCHEMAS: Record<string, ResourceSchema> = {
  posts: schema('posts', 'Post', [field('title', 'Title'), field('summary', 'Summary')], true),
  pages: schema('pages', 'Page', [field('title', 'Title'), field('body', 'Body')], true),
  comments: schema(
    'comments',
    'Comment',
    [field('post', 'Post', { type: 'belongs_to', relatedResource: 'posts' }), field('body', 'Body')],
    false,
  ),
}

const PARENTS: Record<string, { id: number; _title: string }> = {
  '1': { id: 1, _title: 'First post' },
  '2': { id: 2, _title: 'Second post' },
}

const REPLICAS: Record<string, Record<string, unknown>> = {
  '1': { title: 'First post (copy)', summary: 'About the first post' },
  '2': { title: 'Second post (copy)', summary: 'About the second post' },
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    const schemaPath = /^\/api\/resources\/([^/]+)\/schema$/.exec(path)
    if (schemaPath) return Promise.resolve({ data: SCHEMAS[schemaPath[1]] })
    const replicaPath = /^\/api\/resources\/posts\/([^/]+)\/replicate$/.exec(path)
    if (replicaPath) {
      return Promise.resolve({ data: { values: { ...REPLICAS[replicaPath[1]] }, fromResourceId: replicaPath[1] } })
    }
    const parentPath = /^\/api\/resources\/posts\/([^/?]+)$/.exec(path)
    if (parentPath) return Promise.resolve({ data: PARENTS[parentPath[1]] })
    return Promise.resolve({ data: [] })
  })
  // Left pending: a settled create redirects, and only the request matters.
  apiPostMock.mockReturnValue(new Promise(() => {}))
})

function renderAt(url: string) {
  const router = createMemoryRouter(
    // Mirrors router.tsx: the same element for every resource's create page.
    [{ path: '/resources/:resource/create', element: <ResourceCreatePage /> }],
    { initialEntries: [url] },
  )
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
  return router
}

// The router really moved: a navigation that failed in the harness would
// otherwise look exactly like the page keeping the previous target.
async function waitForLocation(router: ReturnType<typeof renderAt>, url: string) {
  await waitFor(() => expect(`${router.state.location.pathname}${router.state.location.search}`).toBe(url))
}

function input(attribute: string): HTMLInputElement | null {
  return document.getElementById(attribute) as HTMLInputElement | null
}

async function waitForValue(attribute: string, value: string) {
  await waitFor(() => expect(input(attribute)?.value).toBe(value))
}

function type(attribute: string, value: string) {
  fireEvent.change(input(attribute)!, { target: { value } })
}

async function create(label: string): Promise<[string, Record<string, unknown>]> {
  const before = apiPostMock.mock.calls.length
  fireEvent.click(screen.getByRole('button', { name: label }))
  await waitFor(() => expect(apiPostMock.mock.calls.length).toBe(before + 1))
  return apiPostMock.mock.calls[before] as [string, Record<string, unknown>]
}

describe('ResourceCreatePage — the route moves to another target', () => {
  it("starts another resource's form empty, without the input the user discarded", async () => {
    const router = renderAt('/resources/posts/create')
    await waitForValue('title', '')
    type('title', 'Draft post')

    await act(() => router.navigate('/resources/pages/create'))
    fireEvent.click(await screen.findByTestId('unsaved-discard'))

    await waitForLocation(router, '/resources/pages/create')
    await screen.findByRole('button', { name: 'Create Page' })
    expect(input('title')?.value).toBe('')
    expect(input('summary')).toBeNull()
    type('body', 'Who we are')
    const [path, body] = await create('Create Page')
    expect(path).toBe('/api/resources/pages')
    expect(body).toEqual({ body: 'Who we are' })
  })

  it("prefills the new parent when the route moves to another parent's nested create", async () => {
    const nested = (parent: string) =>
      `/resources/comments/create?viaResource=posts&viaResourceId=${parent}&viaRelationship=comments`
    const router = renderAt(nested('1'))
    await waitFor(() => expect(apiGetMock).toHaveBeenCalledWith('/api/resources/posts/1'))

    await act(() => router.navigate(nested('2')))

    await waitForLocation(router, nested('2'))
    await waitFor(() => expect(apiGetMock).toHaveBeenCalledWith('/api/resources/posts/2'))
    type('body', 'Nice post')
    const [path, body] = await create('Create Comment')
    expect(path).toBe('/api/resources/posts/2/has-many/comments')
    expect(body).toEqual({ post: { id: '2', title: 'Second post' }, body: 'Nice post' })
  })

  it('replicates the new source when the route moves to another record to replicate', async () => {
    const router = renderAt('/resources/posts/create?fromResourceId=1')
    await waitForValue('title', 'First post (copy)')

    await act(() => router.navigate('/resources/posts/create?fromResourceId=2'))

    await waitForLocation(router, '/resources/posts/create?fromResourceId=2')
    await waitForValue('title', 'Second post (copy)')
    expect(input('summary')?.value).toBe('About the second post')
  })
})

describe('ResourceCreatePage — the route stays on the same target', () => {
  it('keeps what the user typed on a navigation within the same create page', async () => {
    const router = renderAt('/resources/posts/create')
    await waitForValue('title', '')
    type('title', 'Draft post')

    await act(() => router.navigate('/resources/posts/create#summary'))

    expect(router.state.location.hash).toBe('#summary')
    expect(input('title')?.value).toBe('Draft post')
    const [path, body] = await create('Create Post')
    expect(path).toBe('/api/resources/posts')
    expect(body).toEqual({ title: 'Draft post' })
  })

  it('leaves the empty form "Create & add another" starts from a replicated record without a prompt', async () => {
    const router = renderAt('/resources/posts/create?fromResourceId=1')
    await waitForValue('title', 'First post (copy)')
    apiPostMock.mockResolvedValueOnce({ data: { id: 9 } })
    // No `create_and_add_another` translation in the test i18n bundle.
    await create('create_and_add_another')
    await waitForValue('title', '')

    await act(() => router.navigate('/resources/pages/create'))

    await waitForLocation(router, '/resources/pages/create')
    expect(screen.queryByTestId('unsaved-changes-dialog')).toBeNull()
  })
})
