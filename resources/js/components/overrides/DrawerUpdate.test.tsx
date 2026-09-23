import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { useState } from 'react'
import { act, render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import type { FieldDefinition, OverrideProps, ResourceRecord, ResourceSchema } from '@/types'
import type { FieldInputProps } from '@/components/fields/types'

/*
 * The update drawer seeds its form from the record in an effect and mounts
 * the fields only after that, so every input starts from the stored value:
 * an input that reads its value at mount (a custom one included) shows it,
 * and a stored slug counts as set, so editing its source leaves it alone.
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: vi.fn(() => new Promise(() => {})),
      put: vi.fn(() => new Promise(() => {})),
    },
  }
})

// DrawerShell renders children + footer into the DOM so assertions work.
vi.mock('./DrawerShell', () => ({
  DrawerShell: ({ children, footer }: { children: React.ReactNode; footer?: React.ReactNode }) => (
    <div>
      <div data-testid="drawer-content">{children}</div>
      <div data-testid="drawer-footer">{footer}</div>
    </div>
  ),
}))

import { DrawerUpdate } from './DrawerUpdate'
import { api, ApiError } from '@/lib/api'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'
import { componentRegistry } from '@/lib/componentRegistry'

registerDefaultFields()

// A custom input (registered the way a consumer registers one) that reads its
// value once, at mount.
function MountValueProbe({ value }: FieldInputProps) {
  const [mounted] = useState(() => String(value ?? ''))
  return <span data-testid="mount-probe">{mounted}</span>
}
componentRegistry.registerFieldInput('mount_probe', MountValueProbe)

function baseField(overrides: Record<string, unknown>): FieldDefinition {
  return {
    nullable: false, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], reserved: [],
    ...overrides,
  } as unknown as FieldDefinition
}

const titleField = baseField({ attribute: 'title', label: 'Title', type: 'text' })
const slugField = baseField({ attribute: 'slug', label: 'Slug', type: 'slug', sourceAttribute: 'title', separator: '-' })

function renderDrawer(fieldsForUpdate: FieldDefinition[], record: Record<string, unknown>) {
  const schema = {
    uriKey: 'posts', label: 'Posts', singularLabel: 'Post', fields: [], fieldsForUpdate,
    errorDisplay: 'inline', confirmUnsavedChanges: false,
  } as unknown as ResourceSchema
  const props: OverrideProps = {
    schema,
    resource: 'posts',
    params: {},
    record: { id: 1, ...record } as unknown as ResourceRecord,
    recordId: '1',
    navigate: vi.fn(),
    onClose: vi.fn(),
    onCreated: vi.fn(),
    onUpdated: vi.fn(),
    onDeleted: vi.fn(),
    onEdit: vi.fn(),
    onView: vi.fn(),
    addToast: vi.fn(),
  }
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <DrawerUpdate {...props} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('DrawerUpdate record hydration', () => {
  it('mounts the fields with the record values', async () => {
    renderDrawer([baseField({ attribute: 'notes', label: 'Notes', type: 'mount_probe' })], { notes: 'Stored notes' })

    const probe = await screen.findByTestId('mount-probe')
    expect(probe.textContent).toBe('Stored notes')
  })

  it('keeps the stored slug when the title changes', async () => {
    renderDrawer([titleField, slugField], { title: 'Old Title', slug: 'old-title' })

    const titleInput = await waitFor(() => {
      const el = document.getElementById('title') as HTMLInputElement
      expect(el.value).toBe('Old Title')
      return el
    })
    const slugInput = screen.getByTestId('slug-input-slug') as HTMLInputElement

    fireEvent.change(titleInput, { target: { value: 'Brand New' } })

    await waitFor(() => expect(titleInput.value).toBe('Brand New'))
    expect(slugInput.value).toBe('old-title')
  })
})

/*
 * A host can hand the open drawer another record (or another resource's
 * record) without remounting it: ActionDrawer when an index row's Edit or an
 * action response opens another record, the detail page when its route moves
 * to another record while the drawer is open. The drawer must then edit the
 * record it was handed, and nothing from the previous one (values, edits,
 * validation errors, dirty baseline) may carry over.
 */
describe('DrawerUpdate — the host hands it another record', () => {
  const summaryField = baseField({ attribute: 'summary', label: 'Summary', type: 'text' })
  const bodyField = baseField({ attribute: 'body', label: 'Body', type: 'text' })
  const RECORDS: Record<string, Record<string, unknown>> = {
    'posts/1': { id: 1, title: 'First post', summary: 'About the first post' },
    'posts/2': { id: 2, title: 'Second post', summary: 'About the second post' },
    'pages/1': { id: 1, title: 'About us', body: 'Who we are' },
  }

  function schemaFor(uriKey: string, fieldsForUpdate: FieldDefinition[], confirmUnsavedChanges = false): ResourceSchema {
    return {
      uriKey, label: uriKey, singularLabel: uriKey, fields: [], fieldsForUpdate,
      errorDisplay: 'inline', confirmUnsavedChanges,
    } as unknown as ResourceSchema
  }

  const posts = schemaFor('posts', [titleField, summaryField])
  const pages = schemaFor('pages', [titleField, bodyField])

  function propsFor(
    schema: ResourceSchema,
    key: string,
    options: { withRecord?: boolean; record?: Record<string, unknown> } = {},
  ): OverrideProps {
    const [resource, recordId] = key.split('/')
    const record = options.record ?? RECORDS[key]
    return {
      schema,
      resource,
      params: {},
      record: options.withRecord === false ? null : ({ ...record } as unknown as ResourceRecord),
      recordId,
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
          <DrawerUpdate {...p} />
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

  async function save(): Promise<[string, Record<string, unknown>]> {
    const put = vi.mocked(api.put)
    const before = put.mock.calls.length
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(put.mock.calls.length).toBe(before + 1))
    return put.mock.calls[before] as unknown as [string, Record<string, unknown>]
  }

  beforeEach(() => {
    vi.mocked(api.put).mockClear()
    // ActionDrawer hands the drawer only the id; the drawer fetches the record.
    vi.mocked(api.get).mockImplementation(((path: string) => {
      const match = /^\/api\/resources\/([^/]+)\/([^/?]+)\?context=update$/.exec(path)
      const record = match ? RECORDS[`${match[1]}/${match[2]}`] : undefined
      // A record missing from RECORDS stays loading.
      return record ? Promise.resolve({ data: { ...record } }) : new Promise(() => {})
    }) as unknown as typeof api.get)
  })

  afterEach(() => {
    vi.mocked(api.get).mockImplementation((() => new Promise(() => {})) as unknown as typeof api.get)
    vi.mocked(api.put).mockImplementation((() => new Promise(() => {})) as unknown as typeof api.put)
  })

  it('shows and saves the record the host swaps in', async () => {
    const host = renderHost(propsFor(posts, 'posts/1'))
    await waitForValue('title', 'First post')
    type('title', 'First post, edited')

    host.swap(propsFor(posts, 'posts/2'))

    await waitForValue('title', 'Second post')
    expect(input('summary')?.value).toBe('About the second post')
    const [path, body] = await save()
    expect(path).toBe('/api/resources/posts/2')
    expect(body).toEqual({ title: 'Second post', summary: 'About the second post' })
  })

  it('fetches and shows the record when the host swaps only the id', async () => {
    const host = renderHost(propsFor(posts, 'posts/1', { withRecord: false }))
    await waitForValue('title', 'First post')

    host.swap(propsFor(posts, 'posts/2', { withRecord: false }))

    await waitForValue('title', 'Second post')
    const [path, body] = await save()
    expect(path).toBe('/api/resources/posts/2')
    expect(body).toEqual({ title: 'Second post', summary: 'About the second post' })
  })

  it("shows another resource's form when the host swaps the resource", async () => {
    const host = renderHost(propsFor(posts, 'posts/1'))
    await waitForValue('title', 'First post')

    host.swap(propsFor(pages, 'pages/1'))

    await waitForValue('title', 'About us')
    expect(input('body')?.value).toBe('Who we are')
    expect(input('summary')).toBeNull()
    const [path, body] = await save()
    expect(path).toBe('/api/resources/pages/1')
    expect(body).toEqual({ title: 'About us', body: 'Who we are' })
  })

  it("drops the previous record's validation errors", async () => {
    vi.mocked(api.put).mockRejectedValueOnce(
      new ApiError(422, 'The given data was invalid.', [{ field: 'title', message: 'The title is taken.', code: 'unique' }]),
    )
    const host = renderHost(propsFor(posts, 'posts/1'))
    await waitForValue('title', 'First post')
    await save()
    expect(await screen.findByText('The title is taken.')).toBeTruthy()

    host.swap(propsFor(posts, 'posts/2'))

    await waitForValue('title', 'Second post')
    expect(screen.queryByText('The title is taken.')).toBeNull()
  })

  it('closes without an unsaved-changes prompt once the swapped-in record is untouched', async () => {
    const guarded = schemaFor('posts', [titleField, summaryField], true)
    const host = renderHost(propsFor(guarded, 'posts/1'))
    await waitForValue('title', 'First post')
    type('title', 'First post, edited')
    const next = propsFor(guarded, 'posts/2')

    host.swap(next)
    await waitForValue('title', 'Second post')
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    await waitFor(() => expect(next.onClose).toHaveBeenCalled())
    expect(screen.queryByTestId('unsaved-changes-dialog')).toBeNull()
  })

  it('closes without an unsaved-changes prompt while the swapped-in record loads', async () => {
    const guarded = schemaFor('posts', [titleField, summaryField], true)
    const host = renderHost(propsFor(guarded, 'posts/1'))
    await waitForValue('title', 'First post')
    type('title', 'First post, edited')
    const next = propsFor(guarded, 'posts/3', { withRecord: false })

    host.swap(next)
    await waitFor(() => expect(input('title')).toBeNull())
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    await waitFor(() => expect(next.onClose).toHaveBeenCalled())
    expect(screen.queryByTestId('unsaved-changes-dialog')).toBeNull()
  })

  it('keeps the edits when the host hands it a fresh copy of the same record', async () => {
    const host = renderHost(propsFor(posts, 'posts/1'))
    await waitForValue('title', 'First post')
    type('title', 'First post, edited')

    host.swap(propsFor(posts, 'posts/1', { record: { ...RECORDS['posts/1'], title: 'First post, changed elsewhere' } }))

    await waitFor(() => expect(screen.getByRole('button', { name: 'Save changes' })).toBeTruthy())
    expect(input('title')?.value).toBe('First post, edited')
    const [path, body] = await save()
    expect(path).toBe('/api/resources/posts/1')
    expect(body.title).toBe('First post, edited')
  })
})

/*
 * A host can keep the drawer open after a save (a `redirectAfter('stay')`
 * override, or one whose target is the page behind it). The values just
 * saved must then count as clean, and what was typed while the request ran
 * must not.
 */
describe('DrawerUpdate — the unsaved-changes baseline after a save', () => {
  const summaryField = baseField({ attribute: 'summary', label: 'Summary', type: 'text' })
  const record = { id: 1, title: 'First post', summary: 'About the first post' }

  function renderStaying() {
    const props: OverrideProps = {
      schema: {
        uriKey: 'posts', label: 'Posts', singularLabel: 'Post', fields: [],
        fieldsForUpdate: [titleField, summaryField], errorDisplay: 'inline', confirmUnsavedChanges: true,
      } as unknown as ResourceSchema,
      resource: 'posts',
      params: {},
      record: { ...record } as unknown as ResourceRecord,
      recordId: '1',
      navigate: vi.fn(),
      onClose: vi.fn(),
      onCreated: vi.fn(),
      // Stays open, as a `redirectAfter('stay')` host does.
      onUpdated: vi.fn(),
      onDeleted: vi.fn(),
      onEdit: vi.fn(),
      onView: vi.fn(),
      addToast: vi.fn(),
    }
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter>
          <DrawerUpdate {...props} />
        </MemoryRouter>
      </QueryClientProvider>,
    )
    return props
  }

  const input = (attribute: string) => document.getElementById(attribute) as HTMLInputElement | null
  const type = (attribute: string, value: string) => fireEvent.change(input(attribute)!, { target: { value } })

  async function openAndSettle() {
    await waitFor(() => expect(input('title')?.value).toBe('First post'))
    // The drawer takes its baseline again 250 ms after it opens, once the
    // inputs that normalise their value on mount have done so.
    await act(() => new Promise((resolve) => setTimeout(resolve, 300)))
  }

  afterEach(() => {
    vi.mocked(api.put).mockImplementation((() => new Promise(() => {})) as unknown as typeof api.put)
  })

  it('closes without an unsaved-changes prompt after its changes are saved', async () => {
    const props = renderStaying()
    await openAndSettle()
    type('title', 'First post, renamed')
    vi.mocked(api.put).mockResolvedValueOnce({ data: { ...record, title: 'First post, renamed' } } as never)

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(props.onUpdated).toHaveBeenCalled())
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    await waitFor(() => expect(props.onClose).toHaveBeenCalled())
    expect(screen.queryByTestId('unsaved-changes-dialog')).toBeNull()
  })

  it('asks before closing when something was typed while the save ran', async () => {
    const props = renderStaying()
    await openAndSettle()
    type('title', 'First post, renamed')
    let settle: (response: unknown) => void = () => {}
    vi.mocked(api.put).mockReturnValueOnce(new Promise((resolve) => { settle = resolve }) as never)
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.put).toHaveBeenCalled())

    type('title', 'First post, renamed again')
    await act(async () => settle({ data: { ...record, title: 'First post, renamed' } }))
    await waitFor(() => expect(props.onUpdated).toHaveBeenCalled())
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(await screen.findByTestId('unsaved-changes-dialog')).toBeTruthy()
    expect(props.onClose).not.toHaveBeenCalled()
  })
})
