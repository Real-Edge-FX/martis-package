import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition, OverrideProps, ResourceRecord, ResourceSchema } from '@/types'

/*
 * A host can hand the open create drawer another resource, or another record
 * to replicate, without remounting it: ActionDrawer when an action response
 * opens another resource's create drawer while one is open, the detail page
 * when its route moves to another record behind an open Replicate drawer.
 * The drawer seeded its values and its dirty baseline once, at mount, so it
 * kept what was typed for the first target and posted it to the second. It
 * now starts over for each target (values, validation errors, dirty
 * baseline), and a fresh copy of the same record keeps the edits.
 */

// The replicate endpoint answers with the values of the record the drawer
// copies, as the server does (`GET /api/resources/{resource}/{id}/replicate`).
const { replicas } = vi.hoisted(() => ({ replicas: new Map<string, Record<string, unknown>>() }))

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: vi.fn((url: string) => {
        const copy = /^\/api\/resources\/([^/]+)\/([^/]+)\/replicate$/.exec(url)
        if (copy) {
          return Promise.resolve({ data: { values: replicas.get(`${copy[1]}/${copy[2]}`) ?? {}, fromResourceId: copy[2] } })
        }
        return new Promise(() => {})
      }),
      post: vi.fn(() => new Promise(() => {})),
    },
  }
})

/** The values the replicate endpoint answers for a record: all but its id. */
function copyOf(resource: string, record: Record<string, unknown>): void {
  const { id, ...values } = record
  replicas.set(`${resource}/${String(id)}`, values)
}

// DrawerShell renders children + footer into the DOM so assertions work.
vi.mock('./DrawerShell', () => ({
  DrawerShell: ({ children, footer }: { children: React.ReactNode; footer?: React.ReactNode }) => (
    <div>
      <div data-testid="drawer-content">{children}</div>
      <div data-testid="drawer-footer">{footer}</div>
    </div>
  ),
}))

import { DrawerCreate } from './DrawerCreate'
import { api, ApiError } from '@/lib/api'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function textField(attribute: string, label: string): FieldDefinition {
  return {
    attribute, label, type: 'text',
    nullable: false, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], reserved: [],
  } as unknown as FieldDefinition
}

function schemaFor(uriKey: string, singularLabel: string, fieldsForCreate: FieldDefinition[], confirmUnsavedChanges = false): ResourceSchema {
  return {
    uriKey, label: `${singularLabel}s`, singularLabel, fields: [], fieldsForCreate,
    errorDisplay: 'inline', confirmUnsavedChanges,
  } as unknown as ResourceSchema
}

const postFields = [textField('title', 'Title'), textField('summary', 'Summary')]
const pageFields = [textField('title', 'Title'), textField('body', 'Body')]
const posts = schemaFor('posts', 'Post', postFields)
const pages = schemaFor('pages', 'Page', pageFields)

function propsFor(schema: ResourceSchema, record: Record<string, unknown> | null = null): OverrideProps {
  if (record) copyOf(schema.uriKey, record)
  return {
    schema,
    resource: schema.uriKey,
    params: {},
    record: record as unknown as ResourceRecord | null,
    recordId: null,
    navigate: vi.fn(),
    onClose: vi.fn(),
    onCreated: vi.fn(),
    onUpdated: vi.fn(),
    onDeleted: vi.fn(),
    onEdit: vi.fn(),
    onView: vi.fn(),
    addToast: vi.fn(),
  }
}

function renderHost(props: OverrideProps) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const tree = (p: OverrideProps) => (
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <DrawerCreate {...p} />
      </MemoryRouter>
    </QueryClientProvider>
  )
  const view = render(tree(props))
  return { swap: (next: OverrideProps) => view.rerender(tree(next)) }
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
  const post = vi.mocked(api.post)
  const before = post.mock.calls.length
  fireEvent.click(screen.getByRole('button', { name: label }))
  await waitFor(() => expect(post.mock.calls.length).toBe(before + 1))
  return post.mock.calls[before] as unknown as [string, Record<string, unknown>]
}

beforeEach(() => {
  vi.mocked(api.post).mockClear()
})

afterEach(() => {
  vi.mocked(api.post).mockImplementation((() => new Promise(() => {})) as unknown as typeof api.post)
})

describe('DrawerCreate — the host hands it another resource', () => {
  it("starts the other resource's form empty and creates only what was typed there", async () => {
    const host = renderHost(propsFor(posts))
    type('title', 'Draft post')
    type('summary', 'About the draft')

    host.swap(propsFor(pages))

    await waitFor(() => expect(input('body')).not.toBeNull())
    expect(input('title')?.value).toBe('')
    expect(input('summary')).toBeNull()
    type('body', 'Who we are')
    const [path, body] = await create('Create Page')
    expect(path).toBe('/api/resources/pages')
    expect(body).toEqual({ body: 'Who we are' })
  })

  it("drops the previous resource's validation errors", async () => {
    vi.mocked(api.post).mockRejectedValueOnce(
      new ApiError(422, 'The given data was invalid.', [{ field: 'title', message: 'The title is taken.', code: 'unique' }]),
    )
    const host = renderHost(propsFor(posts))
    type('title', 'Draft post')
    await create('Create Post')
    expect(await screen.findByText('The title is taken.')).toBeTruthy()

    host.swap(propsFor(pages))

    await waitFor(() => expect(input('body')).not.toBeNull())
    expect(screen.queryByText('The title is taken.')).toBeNull()
  })

  it('closes without an unsaved-changes prompt while the swapped-in form is untouched', async () => {
    const host = renderHost(propsFor(schemaFor('posts', 'Post', postFields, true)))
    type('title', 'Draft post')
    const next = propsFor(schemaFor('pages', 'Page', pageFields, true))

    host.swap(next)
    await waitFor(() => expect(input('body')).not.toBeNull())
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    await waitFor(() => expect(next.onClose).toHaveBeenCalled())
    expect(screen.queryByTestId('unsaved-changes-dialog')).toBeNull()
  })
})

describe('DrawerCreate — the host hands it another record to replicate', () => {
  it('prefills the form from the new record', async () => {
    const host = renderHost(propsFor(posts, { id: 3, title: 'Third post', summary: 'About the third post' }))
    await waitForValue('title', 'Third post')
    type('title', 'Third post (copy)')

    host.swap(propsFor(posts, { id: 4, title: 'Fourth post', summary: 'About the fourth post' }))

    await waitForValue('title', 'Fourth post')
    expect(input('summary')?.value).toBe('About the fourth post')
  })

  it('keeps the edits when the host hands it a fresh copy of the same record', async () => {
    const host = renderHost(propsFor(posts, { id: 3, title: 'Third post', summary: 'About the third post' }))
    await waitForValue('title', 'Third post')
    type('title', 'Third post (copy)')

    host.swap(propsFor(posts, { id: 3, title: 'Third post, changed elsewhere', summary: 'About the third post' }))

    expect(input('title')?.value).toBe('Third post (copy)')
    const [path, body] = await create('Create Post')
    expect(path).toBe('/api/resources/posts')
    expect(body).toEqual({ title: 'Third post (copy)', summary: 'About the third post', fromResourceId: 3 })
  })
})
