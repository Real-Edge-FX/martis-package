import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import type { ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { allowDataRouterNavigation } from '@/test-support/dataRouterNavigation'

/*
 * The create form returns to the `from` query parameter on Cancel. That value
 * comes from the URL, so a crafted link could point it at another origin
 * (`//host`, `/\host`), which React Router 6 would hand to the History API
 * (GHSA-wrjc-x8rr-h8h6). Only a same-origin path is followed; anything else
 * falls back to the default destination.
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
allowDataRouterNavigation()

const comments = {
  uriKey: 'comments',
  label: 'Comments',
  singularLabel: 'Comment',
  fields: [],
  fieldsForCreate: [],
  errorDisplay: 'inline',
  confirmUnsavedChanges: false,
  messages: {},
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/comments/schema') return Promise.resolve({ data: comments })
    return Promise.resolve({ data: [] })
  })
})

async function cancelFrom(from: string) {
  const router = createMemoryRouter(
    [
      { path: '/resources/:resource/create', element: <ResourceCreatePage /> },
      { path: '*', element: <div data-testid="landed" /> },
    ],
    { initialEntries: [`/resources/comments/create?from=${encodeURIComponent(from)}`] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )

  fireEvent.click(await screen.findByRole('button', { name: 'Cancel' }))
  await waitFor(() => expect(screen.getByTestId('landed')).toBeTruthy())
  return router.state.location
}

describe('ResourceCreatePage `from` parameter', () => {
  it('returns to a same-origin `from` path on Cancel', async () => {
    const location = await cancelFrom('/resources/posts/1?tab=comments')
    expect(location.pathname + location.search).toBe('/resources/posts/1?tab=comments')
  })

  it.each(['//evil.example/phish', '/\\evil.example/phish'])(
    'ignores the cross-origin `from` value %s',
    async (from) => {
      const location = await cancelFrom(from)
      expect(location.pathname).toBe('/resources/comments')
    },
  )
})
