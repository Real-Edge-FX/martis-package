import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'

/*
 * A locked Tool opens the GateModal, which changes the GateProvider's
 * state. The request that found the lock must not run again because of it:
 * with a provider value rebuilt on every render and an effect that depended
 * on it, `/tools/{uriKey}` refetched in a loop until the API throttle
 * answered 429. The real provider is used here, not a mock.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

import { GateProvider } from '@/contexts/GateContext'
import { ToastProvider } from '@/contexts/ToastContext'
import { ToolPage } from './ToolPage'

describe('ToolPage with the real GateProvider', () => {
  beforeEach(() => {
    apiGetMock.mockReset()
  })

  it('asks for a locked tool once, although opening the gate changes the provider', async () => {
    apiGetMock.mockImplementation(() =>
      Promise.resolve({
        locked: true,
        lock: { reason: 'gated', modal: { title: 'Upgrade to Pro', message: 'Findings need the Pro plan.' } },
        tool: { type: 'tool', name: 'Findings', breadcrumb: null, uriKey: 'findings', icon: null, component: null, menuSection: null, meta: {} },
      }),
    )

    render(
      <ToastProvider>
        <GateProvider>
          <MemoryRouter initialEntries={['/tools/findings']}>
            <Routes>
              <Route path="/tools/:uriKey" element={<ToolPage />} />
            </Routes>
          </MemoryRouter>
        </GateProvider>
      </ToastProvider>,
    )

    expect(await screen.findByRole('heading', { name: 'Upgrade to Pro' })).toBeTruthy()
    // Give a refetch loop time to show itself.
    await new Promise((resolve) => setTimeout(resolve, 200))
    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(1))
  })
})
