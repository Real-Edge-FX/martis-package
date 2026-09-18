import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import type { ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'
import { ApiError } from '@/lib/api'

/*
 * The lens page shared the resource index's blind spot, with a twist: with
 * no `meta.fields` it rendered the "Loading…" placeholder, so a failing
 * lens query sat on "Loading…" forever. Same contract as the index now:
 * inline error state + Retry + toast; "No records found." only for an
 * authoritative empty result.
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

import { ResourceLensPage } from '@/pages/ResourceLens'

function makeSchema(): ResourceSchema {
  return {
    uriKey: 'posts',
    label: 'Posts',
    singularLabel: 'Post',
    softDeletes: false,
    stickyView: false,
    group: null,
    fields: [],
    fieldsForIndex: [],
    errorDisplay: 'inline',
    lenses: [{
      type: 'lens',
      name: 'Popular',
      uriKey: 'popular',
      component: null,
      perPageOptions: [10, 25],
      polling: false,
      pollingInterval: 0,
      showPollingToggle: false,
      defaultFilters: {},
      cacheTtlSeconds: 0,
      meta: {},
    }],
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

const EMPTY_LENS_PAGE = {
  data: [],
  meta: { total: 0, current_page: 1, last_page: 1, per_page: 25, from: null, to: null, fields: [], actions: [] },
}

const LENS_URL = '/api/resources/posts/lenses/popular?'

function mockApi(onLens: () => Promise<unknown>) {
  apiGetMock.mockImplementation((path: string) => {
    if (typeof path === 'string' && path.includes('/schema')) {
      return Promise.resolve({ data: makeSchema() })
    }
    if (typeof path === 'string' && path.startsWith(LENS_URL)) return onLens()
    return Promise.resolve({ data: [] })
  })
}

function lensCallCount(): number {
  return apiGetMock.mock.calls.filter(([p]) => typeof p === 'string' && p.startsWith(LENS_URL)).length
}

function errorState(): HTMLElement | null {
  return document.querySelector<HTMLElement>('.martis-query-error')
}

function renderLensPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/lens/:lens', element: <ResourceLensPage /> }],
    { initialEntries: ['/resources/posts/lens/popular'] },
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

describe('ResourceLensPage — lens fetch failure', () => {
  it('renders the inline error state with the status instead of sitting on "Loading…"', async () => {
    mockApi(() => Promise.reject(new ApiError(500, 'Server Error')))

    renderLensPage()

    await waitFor(() => expect(errorState()).not.toBeNull())
    expect(errorState()?.textContent).toContain('Records could not be loaded')
    expect(errorState()?.textContent).toContain('HTTP 500')
    expect(screen.queryByText('Loading…')).toBeNull()
    expect(screen.queryByText('No records found.')).toBeNull()
  })

  it('fires an error toast on the transition to the error state', async () => {
    mockApi(() => Promise.reject(new ApiError(500, 'Server Error')))

    renderLensPage()

    await waitFor(() => {
      expect(document.querySelector('.martis-toast-message')?.textContent).toContain('Records could not be loaded')
    })
  })

  it('refetches on Retry and recovers into the normal empty state', async () => {
    let fail = true
    mockApi(() => (fail ? Promise.reject(new ApiError(500, 'Server Error')) : Promise.resolve(EMPTY_LENS_PAGE)))

    renderLensPage()

    const retry = await screen.findByRole('button', { name: /try again/i })
    const before = lensCallCount()

    fail = false
    await act(async () => { retry.click() })

    await waitFor(() => expect(lensCallCount()).toBe(before + 1))
    await screen.findByText('No records found.')
    expect(errorState()).toBeNull()
  })

  it('still renders "No records found." for a successful empty lens page (regression)', async () => {
    mockApi(() => Promise.resolve(EMPTY_LENS_PAGE))

    renderLensPage()

    await screen.findByText('No records found.')
    expect(errorState()).toBeNull()
  })
})
