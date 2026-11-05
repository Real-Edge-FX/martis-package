import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * v2.17.0: a confirmText() closure is resolved by the fields request of each
 * opening. Until it answers, a skeleton stands in for the text and the run
 * button waits; a bulk opening sends the selection count.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args), post: vi.fn() } }
})

import { ActionModal, type ActionMeta } from './ActionModal'

const base: ActionMeta = {
  uriKey: 'delete-item', name: 'Delete item', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: true, showOnIndex: true, showOnDetail: true, showInline: true,
  executionMode: 'bulk', standalone: false, sole: false, queued: false, withConfirmation: true,
  confirmText: null, confirmButtonText: null, cancelButtonText: null, modalSize: 'md',
  supportsDryRun: false, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: false, pivotLabel: null,
}

function open(action: ActionMeta, selectedIds: Array<string | number>) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <ToastProvider>
        <MemoryRouter initialEntries={['/resources/items']}>
          <Routes>
            <Route path="/resources/:resource" element={<ActionModal resource="items" action={action} selectedIds={selectedIds} visible onHide={() => {}} onSuccess={() => {}} />} />
          </Routes>
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

const runButton = () => screen.getAllByRole('button').find((b) => b.className.includes('martis-btn-danger')) as HTMLButtonElement

beforeEach(() => { apiGetMock.mockReset() })

describe('ActionModal with a confirmText() closure', () => {
  it('shows a skeleton and waits, then shows the resolved texts as plain text', async () => {
    let answer: (v: unknown) => void = () => {}
    apiGetMock.mockImplementation(() => new Promise((resolve) => { answer = resolve }))
    open({ ...base, dynamicConfirm: true }, [7])

    await waitFor(() => expect(screen.getByTestId('action-confirm-skeleton')).toBeTruthy())
    expect(runButton().disabled).toBe(true)
    expect(String(apiGetMock.mock.calls[0][0])).toContain('resourceId=7')

    answer({ data: { fields: [], confirmText: 'Delete «<b>X</b>» for good?', confirmButtonText: 'Delete «X»', cancelButtonText: null } })

    await waitFor(() => expect(screen.getByText('Delete «<b>X</b>» for good?')).toBeTruthy())
    expect(document.body.querySelector('b')).toBeNull()
    expect(runButton().disabled).toBe(false)
    expect(runButton().textContent).toContain('Delete «X»')
  })

  it('sends the selection count for a bulk opening', async () => {
    apiGetMock.mockResolvedValue({ data: { fields: [], confirmText: 'Delete 3 items?' } })
    open({ ...base, dynamicConfirm: true }, [1, 2, 3])

    await waitFor(() => expect(screen.getByText('Delete 3 items?')).toBeTruthy())
    expect(String(apiGetMock.mock.calls[0][0])).toContain('count=3')
  })

  it('renders a static text at once, with no skeleton', async () => {
    apiGetMock.mockImplementation(() => new Promise(() => {}))
    open({ ...base, confirmText: 'Sure?' }, [7])

    expect(await screen.findByText('Sure?')).toBeTruthy()
    expect(screen.queryByTestId('action-confirm-skeleton')).toBeNull()
  })
})
