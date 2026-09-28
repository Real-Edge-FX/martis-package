import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { act } from 'react'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

/*
 * Martis::scopeNotificationsUsing() narrows the bell on the server only.
 * The documented Echo bridge forwards every notification of the user to
 * `martis:notification-received`, so with a scope the bell must refetch the
 * scoped count instead of adding one (PR #276 review).
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (_key: string, fallback?: string) => fallback ?? _key, i18n: { language: 'en' } }),
}))

const get = vi.fn((url: string) =>
  Promise.resolve(url.includes('unread-count') ? { unread: 0 } : { data: [], meta: { total: 0, unread: 0 } }),
)
vi.mock('@/lib/api', () => ({
  api: {
    get: (url: string) => get(url),
    post: vi.fn(() => Promise.resolve({})),
    delete: vi.fn(() => Promise.resolve({})),
  },
}))

import { config } from '@/lib/config'
import { NotificationBell } from './NotificationBell'
import { martisEventBus } from '@/lib/eventBus'

function setScoped(scoped: boolean | undefined): void {
  ;(config as { notifications?: Record<string, unknown> }).notifications = { enabled: true, poll_interval: 0, scoped }
}

function renderBell() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <NotificationBell />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function unreadCountCalls(): number {
  return get.mock.calls.filter(([url]) => url.includes('unread-count')).length
}

const OTHER_TENANT = { id: 'n-2', title: 'Invoice (tenant 2)', tenant_id: 2 }

describe('NotificationBell real-time push with a notification scope', () => {
  beforeEach(() => {
    get.mockClear()
    martisEventBus.clear('martis:notification-received')
  })
  afterEach(() => setScoped(undefined))

  it('refetches the scoped count instead of counting the push', async () => {
    setScoped(true)
    renderBell()
    await waitFor(() => expect(unreadCountCalls()).toBe(1))

    act(() => {
      martisEventBus.emit('martis:notification-received', OTHER_TENANT)
    })

    await waitFor(() => expect(unreadCountCalls()).toBe(2))
    expect(screen.queryByLabelText(/unread/)).toBeNull()
  })

  it('still counts the push at once when the app registers no scope', async () => {
    setScoped(false)
    renderBell()
    await waitFor(() => expect(unreadCountCalls()).toBe(1))

    act(() => {
      martisEventBus.emit('martis:notification-received', OTHER_TENANT)
    })

    await waitFor(() => expect(screen.getByLabelText('1 unread')).toBeTruthy())
    expect(unreadCountCalls()).toBe(1)
  })
})
