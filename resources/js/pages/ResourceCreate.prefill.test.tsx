import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * `Resource::acceptsPrefill()` lists the query keys a link may use to start
 * the create form (`/resources/posts/create?of=12`). The page forwards only
 * those keys to the schema request, keeps the prefilled schema apart from the
 * plain one, drops a select value the user cannot pick, and never prefills a
 * replicate. `Field::sticky()` keeps a field's value across "Create & add
 * another".
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

function field(attribute: string, label: string, type = 'text', extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
    ...extra,
  } as unknown as FieldDefinition
}

const SCHEMA_PATH = '/api/resources/posts/schema'

function schemaOf(fields: FieldDefinition[], prefillKeys: string[] = []) {
  return {
    uriKey: 'posts', label: 'Posts', singularLabel: 'Post', fields: [], prefillKeys,
    fieldsForCreate: fields, errorDisplay: 'inline', confirmUnsavedChanges: false, messages: {},
  } as unknown as ResourceSchema
}

/** The plain schema, and the prefilled one the server builds from `prefill[of]`. */
function renderCreate(opts: {
  plain: FieldDefinition[]
  prefilled?: FieldDefinition[]
  prefillKeys?: string[]
  search?: string
  extraGet?: (path: string) => unknown
}) {
  apiGetMock.mockImplementation((path: string) => {
    if (path === SCHEMA_PATH) return Promise.resolve({ data: schemaOf(opts.plain, opts.prefillKeys ?? []) })
    if (path.startsWith(`${SCHEMA_PATH}?`)) return Promise.resolve({ data: schemaOf(opts.prefilled ?? opts.plain, opts.prefillKeys ?? []) })
    return opts.extraGet?.(path) ?? Promise.resolve({ data: [] })
  })
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/create', element: <ResourceCreatePage /> }],
    { initialEntries: [`/resources/posts/create${opts.search ?? ''}`] },
  )
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
  return { client }
}

const input = (attribute: string) => document.getElementById(attribute) as HTMLInputElement | null
const schemaCalls = () => apiGetMock.mock.calls.map((call) => call[0] as string).filter((path) => path.startsWith(SCHEMA_PATH))

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
})

describe('ResourceCreatePage: prefill from the URL', () => {
  it('forwards only the declared keys, as prefill[key]', async () => {
    renderCreate({
      plain: [field('code', 'Code')],
      prefilled: [field('code', 'Code', 'text', { defaultValue: '12' })],
      prefillKeys: ['of'],
      search: '?of=12&other=nope&prefill[sneaky]=1',
    })

    await waitFor(() => expect(input('code')?.value).toBe('12'))
    expect(schemaCalls()).toContain(`${SCHEMA_PATH}?prefill%5Bof%5D=12`)
    expect(schemaCalls().some((path) => path.includes('other') || path.includes('sneaky'))).toBe(false)
  })

  it('does not forward a value over 255 characters', async () => {
    renderCreate({ plain: [field('code', 'Code')], prefillKeys: ['of'], search: `?of=${'a'.repeat(256)}` })

    await waitFor(() => expect(input('code')).not.toBeNull())
    expect(schemaCalls()).toEqual([SCHEMA_PATH])
  })

  it('asks for the plain schema when the link carries no declared key', async () => {
    renderCreate({ plain: [field('code', 'Code')], prefillKeys: ['of'], search: '?other=1' })

    await waitFor(() => expect(input('code')).not.toBeNull())
    expect(schemaCalls()).toEqual([SCHEMA_PATH])
  })

  it('keeps the prefilled schema under a key of its own', async () => {
    const { client } = renderCreate({
      plain: [field('code', 'Code')],
      prefilled: [field('code', 'Code', 'text', { defaultValue: '12' })],
      prefillKeys: ['of'],
      search: '?of=12',
    })

    await waitFor(() => expect(input('code')?.value).toBe('12'))
    const keys = client.getQueryCache().getAll().map((query) => query.queryKey)
    expect(keys).toContainEqual(['schema', 'posts', 'prefill', { of: '12' }])
    expect(keys).toContainEqual(['schema', 'posts'])
  })

  it('never prefills a replicate', async () => {
    renderCreate({
      plain: [field('code', 'Code', 'text', { defaultValue: 'plain' })],
      prefilled: [field('code', 'Code', 'text', { defaultValue: '12' })],
      prefillKeys: ['of'],
      search: '?of=12&fromResourceId=1',
      extraGet: (path) => path === '/api/resources/posts/1/replicate'
        ? Promise.resolve({ data: { values: { code: 'copied' }, fromResourceId: 1 } })
        : undefined,
    })

    await waitFor(() => expect(input('code')?.value).toBe('copied'))
    expect(schemaCalls()).toEqual([SCHEMA_PATH])
  })

  it('starts a select empty when the prefilled value is not one of its options', async () => {
    const select = (defaultValue: string) => field('kind', 'Kind', 'select', {
      options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }],
      defaultValue,
    })
    apiPostMock.mockResolvedValue({ data: { id: 9 } })
    renderCreate({ plain: [select('a')], prefilled: [select('crafted')], prefillKeys: ['of'], search: '?of=crafted' })

    await waitFor(() => expect(screen.getByRole('button', { name: 'Create Post' })).toBeTruthy())
    fireEvent.click(screen.getByRole('button', { name: 'Create Post' }))

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    expect((apiPostMock.mock.calls[0] as [string, Record<string, unknown>])[1].kind ?? null).toBeNull()
  })
})

describe('ResourceCreatePage: sticky fields', () => {
  it('keeps a sticky field after "Create & add another" and resets the others', async () => {
    apiPostMock.mockResolvedValue({ data: { id: 9 } })
    renderCreate({
      plain: [
        field('keep', 'Keep', 'text', { sticky: true, defaultValue: 'dflt' }),
        field('drop', 'Drop', 'text', { defaultValue: 'start' }),
      ],
    })

    await waitFor(() => expect(input('keep')?.value).toBe('dflt'))
    fireEvent.change(input('keep')!, { target: { value: 'typed-keep' } })
    fireEvent.change(input('drop')!, { target: { value: 'typed-drop' } })
    // No `create_and_add_another` translation in the test i18n bundle.
    fireEvent.click(screen.getByRole('button', { name: 'create_and_add_another' }))

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(input('drop')?.value).toBe('start'))
    expect(input('keep')?.value).toBe('typed-keep')
  })
})
