import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act, render, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import type { ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { martisEventBus } from '@/lib/eventBus'

/*
 * Part A — ResourceIndex consumes `martis:refresh-index`.
 *
 * The event is already declared in `EventBusEvents` but nobody internal
 * emits or consumes it. These tests pin the consumer side: the index page
 * subscribes on mount and invalidates its own `['resources', resource]`
 * query key when the emitted payload targets this resource (or targets
 * all resources via an empty payload) — mirroring the same query key the
 * page's own mutations already invalidate.
 */

// ---------------------------------------------------------------------------
// Mocks
// ---------------------------------------------------------------------------

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

import { ResourceIndexPage } from '@/pages/ResourceIndex'

function makeSchema(uriKey: string): ResourceSchema {
  return {
    uriKey,
    label: 'Posts',
    singularLabel: 'Post',
    softDeletes: false,
    stickyView: false,
    group: null,
    fields: [],
    fieldsForIndex: [],
    errorDisplay: 'inline',
    messages: {
      created: 'Record created successfully.',
      updated: 'Record updated successfully.',
      deleted: 'Record deleted successfully.',
      restored: 'Record restored successfully.',
      deleteConfirm: 'Delete?',
      archiveConfirm: 'Archive?',
    },
  } as unknown as ResourceSchema
}

function mockSchemaAndIndex(resource: string) {
  apiGetMock.mockImplementation((path: string) => {
    if (typeof path === 'string' && path.includes('/schema')) {
      return Promise.resolve({ data: makeSchema(resource) })
    }
    if (typeof path === 'string' && path.includes(`/api/resources/${resource}`)) {
      return Promise.resolve({ data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 25 } })
    }
    return Promise.resolve({ data: [] })
  })
}

function renderIndexPage(resource: string, qc: QueryClient) {
  const router = createMemoryRouter(
    [{ path: '/resources/:resource', element: <ResourceIndexPage /> }],
    { initialEntries: [`/resources/${resource}`] },
  )
  return render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
  martisEventBus.clear('martis:refresh-index')
})

describe('ResourceIndexPage — martis:refresh-index consumption', () => {
  it('invalidates this resource\'s query when the event targets it by resourceKey', async () => {
    mockSchemaAndIndex('posts')
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    })
    const invalidateSpy = vi.spyOn(qc, 'invalidateQueries')

    renderIndexPage('posts', qc)

    await waitFor(() => {
      expect(apiGetMock.mock.calls.some(([p]) => typeof p === 'string' && p.includes('/schema'))).toBe(true)
    })

    invalidateSpy.mockClear()

    act(() => {
      martisEventBus.emit('martis:refresh-index', { resourceKey: 'posts' })
    })

    await waitFor(() => {
      expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['resources', 'posts'] })
    })
  })

  it('does NOT invalidate when the event targets a different resourceKey', async () => {
    mockSchemaAndIndex('posts')
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    })
    const invalidateSpy = vi.spyOn(qc, 'invalidateQueries')

    renderIndexPage('posts', qc)

    await waitFor(() => {
      expect(apiGetMock.mock.calls.some(([p]) => typeof p === 'string' && p.includes('/schema'))).toBe(true)
    })

    invalidateSpy.mockClear()

    act(() => {
      martisEventBus.emit('martis:refresh-index', { resourceKey: 'clients' })
    })

    // Give any (incorrect) async invalidation a chance to fire before asserting absence.
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(invalidateSpy).not.toHaveBeenCalledWith({ queryKey: ['resources', 'posts'] })
  })

  it('invalidates when the event has no resourceKey (targets all resources)', async () => {
    mockSchemaAndIndex('posts')
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    })
    const invalidateSpy = vi.spyOn(qc, 'invalidateQueries')

    renderIndexPage('posts', qc)

    await waitFor(() => {
      expect(apiGetMock.mock.calls.some(([p]) => typeof p === 'string' && p.includes('/schema'))).toBe(true)
    })

    invalidateSpy.mockClear()

    act(() => {
      martisEventBus.emit('martis:refresh-index', {})
    })

    await waitFor(() => {
      expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['resources', 'posts'] })
    })
  })
})
