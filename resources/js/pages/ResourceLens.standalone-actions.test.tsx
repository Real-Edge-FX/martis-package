import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * The lens header lists a standalone action only when it is shown on the
 * index, as the index toolbar does (Nova lists the index's actions by
 * `display=index`).
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

import { ResourceLensPage } from '@/pages/ResourceLens'

function standalone(uriKey: string, name: string, showOnIndex: boolean) {
  return {
    uriKey, name, icon: null, showIcon: true, iconColor: null, group: null, destructive: false,
    showOnIndex, showOnDetail: true, showInline: false, executionMode: 'bulk', standalone: true,
    sole: false, queued: false, withConfirmation: false, confirmText: null, confirmButtonText: null,
    cancelButtonText: null, modalSize: 'md', supportsDryRun: false, customComponent: null,
    customComponentProps: {}, logEvents: true, isPivotAction: false, pivotLabel: null,
  }
}

const posts = {
  uriKey: 'posts', label: 'Posts', singularLabel: 'Post', softDeletes: false, stickyView: false,
  fields: [], fieldsForIndex: [], errorDisplay: 'inline', messages: {}, actionsMenuLabel: 'Toolbar actions',
  lenses: [{
    type: 'lens', name: 'Popular', uriKey: 'popular', component: null, perPageOptions: [25], polling: false,
    pollingInterval: 0, showPollingToggle: false, defaultFilters: {}, cacheTtlSeconds: 0, meta: {},
  }],
} as unknown as ResourceSchema

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockImplementation((path: unknown) => {
    if (path === '/api/resources/posts/schema') return Promise.resolve({ data: posts })
    if (typeof path === 'string' && path.startsWith('/api/resources/posts/lenses/popular?')) {
      return Promise.resolve({
        data: [],
        meta: {
          total: 0, current_page: 1, last_page: 1, per_page: 25, fields: [],
          actions: [standalone('report', 'Run report', true), standalone('detail-only', 'Detail only', false)],
        },
      })
    }
    return Promise.resolve({ data: [] })
  })
})

describe('ResourceLensPage standalone actions', () => {
  it('lists the ones shown on the index and leaves out the others', async () => {
    const router = createMemoryRouter([{ path: '/resources/:resource/lens/:lens', element: <ResourceLensPage /> }], { initialEntries: ['/resources/posts/lens/popular'] })
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ToastProvider>
          <RouterProvider router={router} />
        </ToastProvider>
      </QueryClientProvider>,
    )

    fireEvent.click(await screen.findByRole('button', { name: /Toolbar actions/ }))

    expect(await screen.findByText('Run report')).toBeTruthy()
    expect(screen.queryByText('Detail only')).toBeNull()
  })
})
