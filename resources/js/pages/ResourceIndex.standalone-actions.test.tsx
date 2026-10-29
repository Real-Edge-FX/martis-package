import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * The index toolbar lists a standalone action only when the action is shown
 * on the index, as Nova lists the index's actions by `display=index`: an
 * action declared `exceptOnIndex()` or `onlyOnDetail()` has no entry there
 * (a headless action, shown nowhere, is the extreme case).
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

import { ResourceIndexPage } from '@/pages/ResourceIndex'

function standalone(uriKey: string, name: string, flags: { showOnIndex: boolean; showOnDetail: boolean }) {
  return {
    uriKey, name, icon: null, showIcon: true, iconColor: null, group: null, destructive: false,
    showInline: false, executionMode: 'bulk', standalone: true, sole: false, queued: false,
    withConfirmation: false, confirmText: null, confirmButtonText: null, cancelButtonText: null,
    modalSize: 'md', supportsDryRun: false, customComponent: null, customComponentProps: {},
    logEvents: true, isPivotAction: false, pivotLabel: null, ...flags,
  }
}

function mockIndex(actions: unknown[]) {
  apiGetMock.mockImplementation((path: unknown) => {
    if (typeof path === 'string' && path.includes('/schema')) {
      return Promise.resolve({
        data: {
          uriKey: 'posts', label: 'Posts', singularLabel: 'Post', softDeletes: false, stickyView: false,
          group: null, fields: [], fieldsForIndex: [], errorDisplay: 'inline', messages: {},
          actionsMenuLabel: 'Toolbar actions', actions,
        } as unknown as ResourceSchema,
      })
    }
    return Promise.resolve({ data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 25 } })
  })
}

function renderIndex() {
  const router = createMemoryRouter([{ path: '/resources/:resource', element: <ResourceIndexPage /> }], { initialEntries: ['/resources/posts'] })
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => apiGetMock.mockReset())

describe('ResourceIndexPage standalone actions', () => {
  it('lists the ones shown on the index and leaves out the others', async () => {
    mockIndex([
      standalone('report', 'Run report', { showOnIndex: true, showOnDetail: false }),
      standalone('detail-only', 'Detail only', { showOnIndex: false, showOnDetail: true }),
      standalone('headless', 'Headless', { showOnIndex: false, showOnDetail: false }),
    ])
    renderIndex()

    fireEvent.click(await screen.findByRole('button', { name: /Toolbar actions/ }))

    expect(await screen.findByText('Run report')).toBeTruthy()
    expect(screen.queryByText('Detail only')).toBeNull()
    expect(screen.queryByText('Headless')).toBeNull()
  })

  it('shows no toolbar menu when none of them is shown on the index', async () => {
    mockIndex([standalone('detail-only', 'Detail only', { showOnIndex: false, showOnDetail: true })])
    renderIndex()

    await waitFor(() => expect(apiGetMock.mock.calls.some(([p]) => String(p).includes('/schema'))).toBe(true))
    await screen.findByText('Posts')
    expect(screen.queryByRole('button', { name: /Toolbar actions/ })).toBeNull()
  })
})
