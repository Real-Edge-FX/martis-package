import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * A create form opened from a parent's relationship panel
 * (`?viaResource=…&viaResourceId=…&viaRelationship=…`) locks the BelongsTo that
 * points at the parent: the record is created under the parent in the URL,
 * whatever the form sends. The locked BelongsTo kept its inline-create "+",
 * which created another parent and showed it in place of the real one. It
 * offers no "+" now; another BelongsTo of the form keeps its own.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: vi.fn(() => new Promise(() => {})),
    },
  }
})

import { ResourceCreatePage } from '@/pages/ResourceCreate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function belongsTo(attribute: string, label: string, relatedResource: string): FieldDefinition {
  return {
    attribute, label, type: 'belongs_to', relatedResource, showCreateRelationButton: true,
    nullable: false, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
  } as unknown as FieldDefinition
}

const comments = {
  uriKey: 'comments',
  label: 'Comments',
  singularLabel: 'Comment',
  fields: [],
  fieldsForCreate: [belongsTo('post', 'Post', 'posts'), belongsTo('author', 'Author', 'users')],
  errorDisplay: 'inline',
  confirmUnsavedChanges: false,
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/comments/schema') return Promise.resolve({ data: comments })
    if (path === '/api/resources/posts/1') return Promise.resolve({ data: { id: 1, _title: 'First post' } })
    return Promise.resolve({ data: [] })
  })
})

/** The inline-create "+" rendered next to a BelongsTo trigger, if any. */
function createButtonNextTo(trigger: Element): Element | null {
  return trigger.parentElement?.querySelector('.martis-create-related-btn') ?? null
}

describe('ResourceCreatePage nested under a parent', () => {
  it("offers no inline create on the parent's BelongsTo", async () => {
    const router = createMemoryRouter(
      [{ path: '/resources/:resource/create', element: <ResourceCreatePage /> }],
      { initialEntries: ['/resources/comments/create?viaResource=posts&viaResourceId=1&viaRelationship=comments'] },
    )
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ToastProvider>
          <RouterProvider router={router} />
        </ToastProvider>
      </QueryClientProvider>,
    )

    await waitFor(() => expect(document.querySelector('.martis-belongs-to-trigger-label')?.textContent).toBe('First post'))
    const [post, author] = [...document.querySelectorAll<HTMLButtonElement>('.martis-belongs-to-trigger')]
    expect(post.disabled).toBe(true)
    expect(createButtonNextTo(post)).toBeNull()
    expect(author.disabled).toBe(false)
    expect(createButtonNextTo(author)).not.toBeNull()
  })
})
