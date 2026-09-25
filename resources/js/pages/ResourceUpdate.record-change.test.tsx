import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act, render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceRecord, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * The router keeps the update page's instance when the URL moves from one
 * record's edit page to another's: a link inside the form, back/forward
 * between two edit pages, a redirectAfterUpdate() to another record's edit
 * URL. The page must then edit the record in the URL, showing its values and
 * saving them to its id, and never carry the previous record's form over,
 * even the edits the user just agreed to discard.
 */

const apiGetMock = vi.fn()
const apiPutMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      put: (...args: unknown[]) => apiPutMock(...args),
    },
  }
})

import { ResourceUpdatePage } from '@/pages/ResourceUpdate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()
allowDataRouterNavigation()

function textField(attribute: string, label: string): FieldDefinition {
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
  } as unknown as FieldDefinition
}

function schema(uriKey: string, singularLabel: string, fieldsForUpdate: FieldDefinition[]): ResourceSchema {
  return {
    uriKey,
    label: `${singularLabel}s`,
    singularLabel,
    fields: [],
    fieldsForUpdate,
    errorDisplay: 'inline',
    confirmUnsavedChanges: true,
    messages: {},
  } as unknown as ResourceSchema
}

const SCHEMAS: Record<string, ResourceSchema> = {
  posts: schema('posts', 'Post', [textField('title', 'Title'), textField('summary', 'Summary')]),
  pages: schema('pages', 'Page', [textField('title', 'Title'), textField('body', 'Body')]),
}

let records: Record<string, Record<string, unknown>>

beforeEach(() => {
  records = {
    'posts/1': { id: 1, title: 'First post', summary: 'About the first post' },
    'posts/2': { id: 2, title: 'Second post', summary: 'About the second post' },
    'pages/1': { id: 1, title: 'About us', body: 'Who we are' },
  }
  apiGetMock.mockReset()
  apiPutMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    const schemaPath = /^\/api\/resources\/([^/]+)\/schema$/.exec(path)
    if (schemaPath) return Promise.resolve({ data: SCHEMAS[schemaPath[1]] })
    const recordPath = /^\/api\/resources\/([^/]+)\/([^/?]+)\?context=update$/.exec(path)
    if (recordPath) {
      return Promise.resolve({ data: { ...records[`${recordPath[1]}/${recordPath[2]}`] } as ResourceRecord })
    }
    return Promise.resolve({ data: [] })
  })
  // Left pending by default: a settled save redirects, which the tests that
  // need it set up themselves.
  apiPutMock.mockReturnValue(new Promise(() => {}))
})

function renderAt(entries: string[]) {
  const router = createMemoryRouter(
    [
      // Mirrors router.tsx: the same element for every record's edit page.
      { path: '/resources/:resource/:id/edit', element: <ResourceUpdatePage /> },
      { path: '/resources/:resource/:id', element: <div data-testid="detail-page" /> },
    ],
    { initialEntries: entries, initialIndex: entries.length - 1 },
  )
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
  return { router, qc }
}

// The router really moved: a navigation that failed in the harness would
// otherwise look exactly like the page keeping the previous record.
async function waitForLocation(router: ReturnType<typeof renderAt>['router'], pathname: string) {
  await waitFor(() => expect(router.state.location.pathname).toBe(pathname))
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

async function save(): Promise<[string, Record<string, unknown>]> {
  const before = apiPutMock.mock.calls.length
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
  await waitFor(() => expect(apiPutMock.mock.calls.length).toBe(before + 1))
  return apiPutMock.mock.calls[before] as [string, Record<string, unknown>]
}

describe('ResourceUpdatePage — the route moves to another record', () => {
  it('loads the next record of the same resource and saves its values to its id', async () => {
    const { router } = renderAt(['/resources/posts/1/edit'])
    await waitForValue('title', 'First post')

    await act(() => router.navigate('/resources/posts/2/edit'))

    await waitForLocation(router, '/resources/posts/2/edit')
    await waitForValue('title', 'Second post')
    expect(input('summary')?.value).toBe('About the second post')
    const [path, body] = await save()
    expect(path).toBe('/api/resources/posts/2')
    expect(body).toEqual({ title: 'Second post', summary: 'About the second post' })
  })

  it('drops the edits the user discarded when leaving for another record', async () => {
    const { router } = renderAt(['/resources/posts/1/edit'])
    await waitForValue('title', 'First post')
    type('title', 'First post, edited')

    await act(() => router.navigate('/resources/posts/2/edit'))
    fireEvent.click(await screen.findByTestId('unsaved-discard'))

    await waitForLocation(router, '/resources/posts/2/edit')
    await waitForValue('title', 'Second post')
    const [path, body] = await save()
    expect(path).toBe('/api/resources/posts/2')
    expect(body.title).toBe('Second post')
  })

  it('loads the previous record on a back navigation between two edit pages', async () => {
    const { router } = renderAt(['/resources/posts/1/edit', '/resources/posts/2/edit'])
    await waitForValue('title', 'Second post')

    await act(() => router.navigate(-1))

    await waitForLocation(router, '/resources/posts/1/edit')
    await waitForValue('title', 'First post')
    const [path, body] = await save()
    expect(path).toBe('/api/resources/posts/1')
    expect(body).toEqual({ title: 'First post', summary: 'About the first post' })
  })

  it("follows a redirectAfterUpdate() to another record's edit page with that record's values", async () => {
    const { router } = renderAt(['/resources/posts/1/edit'])
    await waitForValue('title', 'First post')
    type('title', 'First post, renamed')
    apiPutMock.mockResolvedValueOnce({
      data: { id: 1, title: 'First post, renamed', summary: 'About the first post' },
      meta: { redirectTo: '/resources/posts/2/edit' },
    })

    const [firstPath, firstBody] = await save()
    expect(firstPath).toBe('/api/resources/posts/1')
    expect(firstBody.title).toBe('First post, renamed')

    await waitForLocation(router, '/resources/posts/2/edit')
    await waitForValue('title', 'Second post')
    const [path, body] = await save()
    expect(path).toBe('/api/resources/posts/2')
    expect(body).toEqual({ title: 'Second post', summary: 'About the second post' })
  })

  it("loads another resource's form when the route moves to a record of another resource", async () => {
    const { router } = renderAt(['/resources/posts/1/edit'])
    await waitForValue('title', 'First post')

    await act(() => router.navigate('/resources/pages/1/edit'))

    await waitForLocation(router, '/resources/pages/1/edit')
    await waitForValue('title', 'About us')
    expect(input('body')?.value).toBe('Who we are')
    expect(input('summary')).toBeNull()
    const [path, body] = await save()
    expect(path).toBe('/api/resources/pages/1')
    expect(body).toEqual({ title: 'About us', body: 'Who we are' })
  })
})

describe('ResourceUpdatePage — the route stays on the same record', () => {
  it('keeps the edits when the record is fetched again', async () => {
    const { qc } = renderAt(['/resources/posts/1/edit'])
    await waitForValue('title', 'First post')
    type('title', 'First post, edited')
    records['posts/1'] = { ...records['posts/1'], title: 'First post, changed elsewhere' }
    const recordFetches = () => apiGetMock.mock.calls.filter(([p]) => p === '/api/resources/posts/1?context=update').length
    const fetchesBefore = recordFetches()

    await act(() => qc.invalidateQueries({ queryKey: ['resource', 'posts', '1'] }))

    await waitFor(() => expect(recordFetches()).toBe(fetchesBefore + 1))
    expect(input('title')?.value).toBe('First post, edited')
    const [, body] = await save()
    expect(body.title).toBe('First post, edited')
  })

  it("counts the saved values as clean after a redirectAfterUpdate() to the record's own edit page", async () => {
    const { router } = renderAt(['/resources/posts/1/edit'])
    await waitForValue('title', 'First post')
    type('title', 'First post, renamed')
    apiPutMock.mockResolvedValueOnce({
      data: { id: 1, title: 'First post, renamed', summary: 'About the first post' },
      meta: { redirectTo: '/resources/posts/1/edit' },
    })
    await save()
    await waitFor(() => expect(router.state.historyAction).toBe('PUSH'))
    expect(input('title')?.value).toBe('First post, renamed')

    await act(() => router.navigate('/resources/posts/2/edit'))

    expect(screen.queryByTestId('unsaved-changes-dialog')).toBeNull()
    await waitForLocation(router, '/resources/posts/2/edit')
  })

  it('counts the saved values as clean after "Save & continue editing"', async () => {
    const { router } = renderAt(['/resources/posts/1/edit'])
    await waitForValue('title', 'First post')
    type('title', 'First post, renamed')
    apiPutMock.mockResolvedValueOnce({ data: { id: 1, title: 'First post, renamed', summary: 'About the first post' } })
    fireEvent.click(screen.getByRole('button', { name: /continue/i }))
    await waitFor(() => expect(apiPutMock).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save changes' })).toHaveProperty('disabled', false))

    await act(() => router.navigate('/resources/posts/2/edit'))

    expect(screen.queryByTestId('unsaved-changes-dialog')).toBeNull()
    await waitForLocation(router, '/resources/posts/2/edit')
  })

  it('counts what is typed while "Save & continue editing" runs as unsaved', async () => {
    const { router } = renderAt(['/resources/posts/1/edit'])
    await waitForValue('title', 'First post')
    type('title', 'First post, renamed')
    let settle: (response: unknown) => void = () => {}
    apiPutMock.mockReturnValueOnce(new Promise((resolve) => { settle = resolve }))
    fireEvent.click(screen.getByRole('button', { name: /continue/i }))
    await waitFor(() => expect(apiPutMock).toHaveBeenCalledTimes(1))
    expect((apiPutMock.mock.calls[0] as [string, Record<string, unknown>])[1].title).toBe('First post, renamed')

    type('title', 'First post, renamed again')
    await act(async () => settle({ data: { id: 1, title: 'First post, renamed', summary: 'About the first post' } }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save changes' })).toHaveProperty('disabled', false))

    await act(() => router.navigate('/resources/posts/2/edit'))

    expect(await screen.findByTestId('unsaved-changes-dialog')).toBeTruthy()
    expect(router.state.location.pathname).toBe('/resources/posts/1/edit')
    expect(input('title')?.value).toBe('First post, renamed again')
  })

  it("counts what is typed while a save that redirects to the record's own edit page runs as unsaved", async () => {
    const { router } = renderAt(['/resources/posts/1/edit'])
    await waitForValue('title', 'First post')
    type('title', 'First post, renamed')
    let settle: (response: unknown) => void = () => {}
    apiPutMock.mockReturnValueOnce(new Promise((resolve) => { settle = resolve }))
    await save()

    type('title', 'First post, renamed again')
    await act(async () =>
      settle({
        data: { id: 1, title: 'First post, renamed', summary: 'About the first post' },
        meta: { redirectTo: '/resources/posts/1/edit' },
      }),
    )
    await waitFor(() => expect(router.state.historyAction).toBe('PUSH'))

    await act(() => router.navigate('/resources/posts/2/edit'))

    expect(await screen.findByTestId('unsaved-changes-dialog')).toBeTruthy()
    expect(router.state.location.pathname).toBe('/resources/posts/1/edit')
  })

  it('keeps the edits on a navigation within the same edit page', async () => {
    const { router } = renderAt(['/resources/posts/1/edit'])
    await waitForValue('title', 'First post')
    type('title', 'First post, edited')

    await act(() => router.navigate('/resources/posts/1/edit#summary'))

    expect(router.state.location.hash).toBe('#summary')
    expect(input('title')?.value).toBe('First post, edited')
    const [path, body] = await save()
    expect(path).toBe('/api/resources/posts/1')
    expect(body.title).toBe('First post, edited')
  })
})
