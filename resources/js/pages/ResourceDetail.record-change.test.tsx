import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act, render, screen, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * The router keeps the detail page's instance when the URL moves to another
 * record (the command palette opens over any confirmation). The drawers of
 * the page follow the record in the URL, but the delete, force-delete and
 * restore confirmations and the action modal stayed open for the new record:
 * confirming the delete opened for one record deleted the other. They belong
 * to the record they were opened for, so the page closes them when its record
 * changes.
 */

const apiGetMock = vi.fn()
const apiDeleteMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      delete: (...args: unknown[]) => apiDeleteMock(...args),
    },
  }
})

import { ResourceDetailPage } from '@/pages/ResourceDetail'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()
allowDataRouterNavigation()

const titleField = {
  attribute: 'title', label: 'Title', type: 'text',
  nullable: false, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
} as unknown as FieldDefinition

const posts = {
  uriKey: 'posts',
  label: 'Posts',
  singularLabel: 'Post',
  softDeletes: false,
  fields: [],
  fieldsForDetail: [titleField],
  actions: [],
  messages: {},
} as unknown as ResourceSchema

const RECORDS: Record<string, Record<string, unknown>> = {
  '3': { id: 3, _title: 'Third post', title: 'Third post' },
  '4': { id: 4, _title: 'Fourth post', title: 'Fourth post' },
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiDeleteMock.mockReset()
  apiDeleteMock.mockReturnValue(new Promise(() => {}))
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/posts/schema') return Promise.resolve({ data: posts })
    const recordPath = /^\/api\/resources\/posts\/([^/?]+)$/.exec(path)
    if (recordPath) return Promise.resolve({ data: RECORDS[recordPath[1]] })
    return Promise.resolve({ data: [] })
  })
})

describe('ResourceDetailPage — the route moves to another record', () => {
  it('closes the delete confirmation of the previous record', async () => {
    const router = createMemoryRouter(
      [{ path: '/resources/:resource/:id', element: <ResourceDetailPage /> }],
      { initialEntries: ['/resources/posts/3'] },
    )
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ToastProvider>
          <RouterProvider router={router} />
        </ToastProvider>
      </QueryClientProvider>,
    )
    await screen.findByRole('heading', { name: 'Third post' })
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    expect(await screen.findByRole('dialog')).toBeTruthy()

    await act(() => router.navigate('/resources/posts/4'))

    await screen.findByRole('heading', { name: 'Fourth post' })
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(apiDeleteMock).not.toHaveBeenCalled()
  })
})
