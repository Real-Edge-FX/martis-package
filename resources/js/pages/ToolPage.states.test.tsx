import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { ApiError } from '@/lib/api'

/*
 * The hidden and locked states of `/tools/{uriKey}`. They are pinned here
 * before the resolution moves to `useToolDescriptor`, which registered
 * pages bound to a Tool reuse (v2.2.0).
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

const gateOpen = vi.fn()
// One object for every render, as the provider's value is: a new object per
// render would re-run the fetch effect, which depends on the gate.
const gate = { isOpen: false, lock: null, open: gateOpen, close: vi.fn() }

vi.mock('@/contexts/GateContext', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/contexts/GateContext')>()),
  useGateOptional: () => gate,
}))

import { ToastProvider } from '@/contexts/ToastContext'
import { ToolPage } from './ToolPage'

function renderTool(): void {
  render(
    <ToastProvider>
      <MemoryRouter initialEntries={['/tools/findings']}>
        <Routes>
          <Route path="/tools/:uriKey" element={<ToolPage />} />
        </Routes>
      </MemoryRouter>
    </ToastProvider>,
  )
}

const descriptor = {
  type: 'tool' as const,
  name: 'Findings',
  breadcrumb: null,
  uriKey: 'findings',
  icon: null,
  component: null,
  menuSection: null,
  meta: {},
}

describe('ToolPage states', () => {
  beforeEach(() => {
    apiGetMock.mockReset()
    gateOpen.mockReset()
  })

  it('shows the not-found state when the API answers 404', async () => {
    apiGetMock.mockRejectedValue(new ApiError(404, 'Tool not found.'))

    renderTool()

    expect(await screen.findByText('Tool not found')).toBeTruthy()
    expect(screen.getByText('This tool does not exist or you do not have permission to see it.')).toBeTruthy()
  })

  it('shows the lock page and opens the gate when the tool is locked', async () => {
    const lock = { reason: 'gated', modal: { title: 'Upgrade to Pro', message: 'Findings need the Pro plan.' } }
    apiGetMock.mockResolvedValue({ locked: true, lock, tool: descriptor })

    renderTool()

    expect(await screen.findByRole('heading', { name: 'Upgrade to Pro' })).toBeTruthy()
    expect(screen.getByText('Findings need the Pro plan.')).toBeTruthy()
    expect(gateOpen).toHaveBeenCalledWith(lock)
  })
})
