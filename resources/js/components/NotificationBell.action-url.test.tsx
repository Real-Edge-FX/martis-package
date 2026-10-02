import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

/*
 * Hardening (F104): a notification's `action_url` that is not a same-origin
 * path went straight to `window.open`, whatever its scheme. The notification
 * payload comes from application code that may build it from stored data, so
 * the bell now opens only http(s) URLs and refuses the rest with a console
 * error.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (_key: string, fallback?: string) => fallback ?? _key, i18n: { language: 'en' } }),
}))

const notifications = vi.hoisted(() => ({ items: [] as Array<Record<string, unknown>> }))

vi.mock('@/lib/api', () => ({
  api: {
    get: (url: string) =>
      Promise.resolve(
        url.includes('unread-count')
          ? { unread: notifications.items.length }
          : { data: notifications.items, meta: { total: notifications.items.length, unread: notifications.items.length } },
      ),
    post: vi.fn(() => Promise.resolve({})),
    delete: vi.fn(() => Promise.resolve({})),
  },
}))

import { config } from '@/lib/config'
import { NotificationBell } from './NotificationBell'

function notification(actionUrl: string | null): Record<string, unknown> {
  return {
    id: 'n-1', type: 'x', title: 'Invoice paid', message: null, level: 'info', icon: null,
    action_url: actionUrl, action_label: 'Open', read_at: '2026-10-01T00:00:00Z', created_at: '2026-10-01T00:00:00Z',
  }
}

async function openAndClick(actionUrl: string | null) {
  notifications.items = [notification(actionUrl)]
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <NotificationBell />
      </MemoryRouter>
    </QueryClientProvider>,
  )

  fireEvent.click(screen.getByRole('button', { name: 'Notifications' }))
  const item = await waitFor(() => screen.getByText('Invoice paid'))
  fireEvent.click(item)
}

let openSpy: MockInstance<typeof window.open>
let errorSpy: MockInstance<typeof console.error>

beforeEach(() => {
  ;(config as { notifications?: Record<string, unknown> }).notifications = { enabled: true, poll_interval: 0 }
  openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
  errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
  ;(config as { notifications?: Record<string, unknown> }).notifications = undefined
  vi.restoreAllMocks()
})

describe('NotificationBell action_url', () => {
  it.each(['javascript:alert(1)', ' javascript:alert(1)', 'java\tscript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'vbscript:msgbox(1)'])(
    'does not open %j',
    async (url) => {
      await openAndClick(url)

      expect(openSpy).not.toHaveBeenCalled()
      expect(errorSpy).toHaveBeenCalled()
    },
  )

  it('opens an absolute http(s) URL in a new tab without an opener', async () => {
    await openAndClick('https://example.com/invoices/1')

    expect(openSpy).toHaveBeenCalledWith('https://example.com/invoices/1', '_blank', 'noopener,noreferrer')
  })

  // A contact link a person chose to publish opens as it does in the sidebar.
  it.each(['mailto:billing@example.com', 'tel:+351210000000'])('opens %s in a new tab', async (url) => {
    await openAndClick(url)

    expect(openSpy).toHaveBeenCalledWith(url, '_blank', 'noopener,noreferrer')
    expect(errorSpy).not.toHaveBeenCalled()
  })

  it.each(['sms:+351210000000', 'file:///etc/passwd', 'blob:https://example.com/0f0f0f0f'])('still refuses %s', async (url) => {
    await openAndClick(url)

    expect(openSpy).not.toHaveBeenCalled()
    expect(errorSpy).toHaveBeenCalled()
  })

  it('does not open a window for a same-origin path: it goes through the router', async () => {
    await openAndClick('/resources/invoices/1')

    expect(openSpy).not.toHaveBeenCalled()
    expect(errorSpy).not.toHaveBeenCalled()
  })
})
