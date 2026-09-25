import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useState } from 'react'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * Replicate opens the create form filled with a copy of the record. The page
 * mounted the fields as soon as the copy arrived and filled the form one
 * render later, so every input mounted empty and received the copy as a
 * change: an input that reads its value when it mounts showed nothing, and a
 * Slug took the copy for the title changing and overwrote the copied slug
 * with one made from the title. The page now mounts the fields once the copy
 * has filled the form, as Nova's Replicate view does, and a Slug on a create
 * form keeps the slug it mounts with until its source changes.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
    },
  }
})

import { ResourceCreatePage } from '@/pages/ResourceCreate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'
import { componentRegistry } from '@/lib/componentRegistry'
import { ApiError } from '@/lib/api'
import type { FieldInputProps } from '@/components/fields/types'

registerDefaultFields()

// A custom input (registered the way a consumer registers one) that reads its
// value once, at mount.
function MountValueProbe({ value }: FieldInputProps) {
  const [mounted] = useState(() => String(value ?? ''))
  return <span data-testid="mount-probe">{mounted}</span>
}
componentRegistry.registerFieldInput('mount_probe', MountValueProbe)

function field(attribute: string, label: string, extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute,
    label,
    type: 'text',
    nullable: true,
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

const schema = {
  uriKey: 'posts',
  label: 'Posts',
  singularLabel: 'Post',
  fields: [],
  fieldsForCreate: [
    field('title', 'Title'),
    field('slug', 'Slug', { type: 'slug', sourceAttribute: 'title', separator: '-' }),
    field('notes', 'Notes', { type: 'mount_probe' }),
  ],
  errorDisplay: 'inline',
  confirmUnsavedChanges: false,
  messages: {},
} as unknown as ResourceSchema

let replicate: () => Promise<unknown>

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/posts/schema') return Promise.resolve({ data: schema })
    if (path === '/api/resources/posts/1/replicate') return replicate()
    // The slug availability check.
    return Promise.resolve({ data: { available: true, suggestion: null, reserved: false } })
  })
})

function copyOf(values: Record<string, unknown>) {
  return () => Promise.resolve({ data: { values, fromResourceId: 1 } })
}

function renderReplicate() {
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/create', element: <ResourceCreatePage /> }],
    { initialEntries: ['/resources/posts/create?fromResourceId=1'] },
  )
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

const input = (attribute: string) => document.getElementById(attribute) as HTMLInputElement | null
const slug = () => screen.getByTestId('slug-input-slug') as HTMLInputElement

describe('ResourceCreatePage — replicating a record', () => {
  it('mounts the fields once the copy has filled the form', async () => {
    replicate = copyOf({ title: 'First post (copy)', slug: 'first-post', notes: 'Copied notes' })

    renderReplicate()

    expect((await screen.findByTestId('mount-probe')).textContent).toBe('Copied notes')
    expect(input('title')?.value).toBe('First post (copy)')
  })

  it('keeps the copied slug on load and follows the title once it changes', async () => {
    replicate = copyOf({ title: 'First post (copy)', slug: 'first-post', notes: null })

    renderReplicate()

    await waitFor(() => expect(input('title')?.value).toBe('First post (copy)'))
    expect(slug().value).toBe('first-post')
    fireEvent.change(input('title')!, { target: { value: 'Brand new post' } })
    await waitFor(() => expect(slug().value).toBe('brand-new-post'))
  })

  it('does not overwrite a custom copied slug on load', async () => {
    replicate = copyOf({ title: 'First post (copy)', slug: 'my-custom-slug', notes: null })

    renderReplicate()

    await waitFor(() => expect(input('title')?.value).toBe('First post (copy)'))
    expect(slug().value).toBe('my-custom-slug')
  })

  it('shows an empty form when the copy carries no values', async () => {
    replicate = () => Promise.resolve({ data: { fromResourceId: 1 } })

    renderReplicate()

    await waitFor(() => expect(input('title')).not.toBeNull())
    expect(input('title')?.value).toBe('')
  })

  it('shows the error page when the copy cannot be loaded', async () => {
    replicate = () => Promise.reject(new ApiError(404, 'Not found'))

    renderReplicate()

    expect(await screen.findByText('Resource not found')).toBeTruthy()
    expect(input('title')).toBeNull()
  })
})
