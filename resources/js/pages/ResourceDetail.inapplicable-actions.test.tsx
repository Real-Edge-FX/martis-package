import { describe, it, expect, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * v2.17.0: the detail page reads the record's `_actionAuthorization` (the
 * run verdict per action) and grays out an action the record cannot run,
 * or leaves it out when the action says whenInapplicable(Hide | KeepSlot).
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

import { ResourceDetailPage } from '@/pages/ResourceDetail'

const action = (uriKey: string, whenInapplicable?: string) => ({
  uriKey, name: uriKey, icon: null, showIcon: true, iconColor: null, group: null, destructive: false,
  showOnIndex: true, showOnDetail: true, showInline: true, standalone: false, whenInapplicable,
})

const schema = {
  uriKey: 'items', label: 'Items', singularLabel: 'Item', softDeletes: false, fields: [], fieldsForDetail: [],
  actions: [action('archive'), action('switch-on', 'hide'), action('move-up', 'keep_slot'), action('switch-off', 'hide')],
  messages: {},
} as unknown as ResourceSchema

describe('ResourceDetailPage actions for a record that cannot run them', () => {
  it('grays out a Disabled action, leaves out Hide and KeepSlot, and keeps the runnable ones', async () => {
    apiGetMock.mockImplementation((path: string) => {
      if (path === '/api/resources/items/schema') return Promise.resolve({ data: schema })
      if (path === '/api/resources/items/1') {
        return Promise.resolve({ data: { id: 1, _title: 'Spring guide', _actionAuthorization: { archive: false, 'switch-on': false, 'move-up': false, 'switch-off': true } } })
      }
      return Promise.resolve({ data: [] })
    })
    const router = createMemoryRouter([{ path: '/resources/:resource/:id', element: <ResourceDetailPage /> }], { initialEntries: ['/resources/items/1'] })
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ToastProvider><RouterProvider router={router} /></ToastProvider>
      </QueryClientProvider>,
    )

    await screen.findByRole('heading', { name: 'Spring guide' })
    fireEvent.click(screen.getByRole('button', { name: /actions/i }))

    expect((screen.getByRole('button', { name: 'archive' }) as HTMLButtonElement).disabled).toBe(true)
    expect((screen.getByRole('button', { name: 'switch-off' }) as HTMLButtonElement).disabled).toBe(false)
    expect(screen.queryByRole('button', { name: 'switch-on' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'move-up' })).toBeNull()
  })
})
