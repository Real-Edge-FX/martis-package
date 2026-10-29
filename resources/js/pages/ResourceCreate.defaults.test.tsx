import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * `Field::default()` is serialised as `defaultValue`, and the docs promise it
 * fills the create form. The page started every form empty. It now starts
 * from the defaults; a replicated record's values replace them, the parent a
 * nested create names wins over a default, and "Create & add another"
 * returns to the defaults.
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

function schemaOf(fields: FieldDefinition[]) {
  return {
    uriKey: 'posts', label: 'Posts', singularLabel: 'Post', fields: [],
    fieldsForCreate: fields, errorDisplay: 'inline', confirmUnsavedChanges: false, messages: {},
  } as unknown as ResourceSchema
}

function renderCreate(fields: FieldDefinition[], search = '', extraGet?: (path: string) => unknown) {
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/posts/schema') return Promise.resolve({ data: schemaOf(fields) })
    const extra = extraGet?.(path)
    return extra ?? Promise.resolve({ data: [] })
  })
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/create', element: <ResourceCreatePage /> }],
    { initialEntries: [`/resources/posts/create${search}`] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

const input = (attribute: string) => document.getElementById(attribute) as HTMLInputElement | null

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
})

describe('ResourceCreatePage: field defaults', () => {
  it('starts the form from each field default', async () => {
    renderCreate([field('code', 'Code', 'text', { defaultValue: 'abc' }), field('notes', 'Notes')])

    await waitFor(() => expect(input('code')?.value).toBe('abc'))
    expect(input('notes')?.value).toBe('')
  })

  it('posts the default the user left untouched', async () => {
    apiPostMock.mockResolvedValue({ data: { id: 9 } })
    renderCreate([field('code', 'Code', 'text', { defaultValue: 'abc' })])

    await waitFor(() => expect(input('code')?.value).toBe('abc'))
    fireEvent.click(screen.getByRole('button', { name: 'Create Post' }))

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    expect((apiPostMock.mock.calls[0] as [string, Record<string, unknown>])[1].code).toBe('abc')
  })

  it('replaces a default with the replicated value', async () => {
    renderCreate(
      [field('code', 'Code', 'text', { defaultValue: 'abc' }), field('kind', 'Kind', 'text', { defaultValue: 'draft' })],
      '?fromResourceId=1',
      (path) => path === '/api/resources/posts/1/replicate'
        ? Promise.resolve({ data: { values: { code: 'copied' }, fromResourceId: 1 } })
        : undefined,
    )

    await waitFor(() => expect(input('code')?.value).toBe('copied'))
    // A field the copy leaves out keeps its default.
    expect(input('kind')?.value).toBe('draft')
  })

  it('lets the parent of a nested create win over a default', async () => {
    renderCreate(
      [field('author_id', 'Author', 'belongs_to', { relatedResource: 'authors', defaultValue: 5 })],
      '?viaResource=authors&viaResourceId=2&viaRelationship=posts',
      (path) => path === '/api/resources/authors/2' ? Promise.resolve({ data: { id: 2, _title: 'Bo' } }) : undefined,
    )

    await waitFor(() => expect(document.querySelector('.martis-belongs-to-trigger-label')?.textContent).toBe('Bo'))
  })

  it('returns to the defaults on "Create & add another"', async () => {
    apiPostMock.mockResolvedValue({ data: { id: 9 } })
    renderCreate([field('code', 'Code', 'text', { defaultValue: 'abc' })])

    await waitFor(() => expect(input('code')?.value).toBe('abc'))
    fireEvent.change(input('code')!, { target: { value: 'typed' } })
    // No `create_and_add_another` translation in the test i18n bundle.
    fireEvent.click(screen.getByRole('button', { name: 'create_and_add_another' }))

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(input('code')?.value).toBe('abc'))
  })

  it('shows the label of a BelongsTo default, not "#id"', async () => {
    renderCreate(
      [field('author_id', 'Author', 'belongs_to', { relatedResource: 'authors', defaultValue: 5 })],
      '',
      (path) => path === '/api/resources/authors/5' ? Promise.resolve({ data: { id: 5, _title: 'Ana' } }) : undefined,
    )

    await waitFor(() => expect(document.querySelector('.martis-belongs-to-trigger-label')?.textContent).toBe('Ana'))
  })
})
