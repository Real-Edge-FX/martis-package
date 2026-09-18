import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import type { ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { ApiError } from '@/lib/api'

/*
 * A failed index fetch must never be rendered as "No records found."
 *
 * The page only handled errors on the *schema* query; the *index* query's
 * error state was never read, and the table was fed `[]` while the request
 * was failing — so a 500 (tenant-context exception, missing table, any
 * server error) looked exactly like an empty resource, with no toast, no
 * banner and no retry. These tests pin the three states of the index
 * query: error (inline error state + Retry + toast), success-empty (the
 * empty state, unchanged) and pending (neither copy shows).
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

const EMPTY_PAGE = { data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 25, from: null, to: null } }

function isIndexCall(path: unknown, resource: string): boolean {
  return typeof path === 'string' && path.startsWith(`/api/resources/${resource}?`)
}

/**
 * Schema always resolves; the index request is delegated to `onIndex` so
 * each test can decide between rejecting, resolving empty or hanging.
 */
function mockApi(resource: string, onIndex: () => Promise<unknown>) {
  apiGetMock.mockImplementation((path: string) => {
    if (typeof path === 'string' && path.includes('/schema')) {
      return Promise.resolve({ data: makeSchema(resource) })
    }
    if (isIndexCall(path, resource)) return onIndex()
    return Promise.resolve({ data: [] })
  })
}

// The inline state carries role="alert", but so does PrimeReact's Toast,
// so target it by its public class hook instead.
function errorState(): HTMLElement | null {
  return document.querySelector<HTMLElement>('.martis-query-error')
}

async function findErrorState(): Promise<HTMLElement> {
  await waitFor(() => expect(errorState()).not.toBeNull())
  return errorState() as HTMLElement
}

function indexCallCount(resource: string): number {
  return apiGetMock.mock.calls.filter(([p]) => isIndexCall(p, resource)).length
}

function renderIndexPage(resource: string) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
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
  document.body.innerHTML = ''
})

describe('ResourceIndexPage — index fetch failure', () => {
  it('renders an inline error state (not the empty state) when the index request fails', async () => {
    mockApi('posts', () => Promise.reject(new ApiError(500, 'Server Error')))

    renderIndexPage('posts')

    const state = await findErrorState()
    expect(state.textContent).toContain('Records could not be loaded')
    // The status code is what lets support tell a failure from an empty
    // resource on a screenshot.
    expect(state.textContent).toContain('HTTP 500')
    expect(screen.queryByText('No records found.')).toBeNull()
  })

  it('fires an error toast on the transition to the error state', async () => {
    mockApi('posts', () => Promise.reject(new ApiError(500, 'Server Error')))

    renderIndexPage('posts')

    await waitFor(() => {
      const toast = document.querySelector('.martis-toast-message')
      expect(toast?.textContent).toContain('Records could not be loaded')
    })
  })

  it('refetches the index when Retry is clicked and recovers once the request succeeds', async () => {
    let fail = true
    mockApi('posts', () => (fail
      ? Promise.reject(new ApiError(500, 'Server Error'))
      : Promise.resolve(EMPTY_PAGE)))

    renderIndexPage('posts')

    const retry = await screen.findByRole('button', { name: /try again/i })
    const before = indexCallCount('posts')

    fail = false
    await act(async () => { retry.click() })

    await waitFor(() => expect(indexCallCount('posts')).toBe(before + 1))
    // Recovery: the error state goes away and the (genuinely) empty
    // resource renders its normal empty state.
    await screen.findByText('No records found.')
    expect(errorState()).toBeNull()
  })

  it('still renders "No records found." for a successful empty page (regression)', async () => {
    mockApi('posts', () => Promise.resolve(EMPTY_PAGE))

    renderIndexPage('posts')

    await screen.findByText('No records found.')
    expect(errorState()).toBeNull()
  })

  it('shows neither the empty state nor the error state while the first fetch is pending', async () => {
    // Never settles: the index query stays pending for the whole test.
    mockApi('posts', () => new Promise(() => {}))

    renderIndexPage('posts')

    // Wait until the schema has loaded and the index request has been issued,
    // then give React a tick to render whatever it would render.
    await waitFor(() => expect(indexCallCount('posts')).toBeGreaterThan(0))
    await act(async () => { await new Promise((r) => setTimeout(r, 20)) })

    expect(screen.queryByText('No records found.')).toBeNull()
    expect(errorState()).toBeNull()
  })

  it('does not fall back to the empty state on a non-HTTP failure (network error)', async () => {
    mockApi('posts', () => Promise.reject(new TypeError('Failed to fetch')))

    renderIndexPage('posts')

    const state = await findErrorState()
    expect(state.textContent).toContain('Records could not be loaded')
    expect(state.dataset.status).toBe('network')
    expect(screen.queryByText('No records found.')).toBeNull()
  })
})
