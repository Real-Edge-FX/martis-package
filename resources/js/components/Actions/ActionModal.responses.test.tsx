import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactElement } from 'react'

// Action answers reach the user (v2.3.0): a modal answer shows its
// component, a visit navigates inside the SPA, pivot actions use the same
// dispatcher, and martis:action-executed fires once per run.

const apiGetMock = vi.fn()
const apiPostMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args), post: (...args: unknown[]) => apiPostMock(...args) } }
})
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))
vi.mock('@/lib/historyLock', () => ({ useModalHistoryLock: () => undefined }))

import { ActionModal, type ActionMeta } from './ActionModal'
import { ActionResponseModalProvider, type ActionResponseModalProps } from './ActionResponseModalHost'
import { PivotActionModal } from '@/components/fields/relation/PivotActionModal'
import { componentRegistry } from '@/lib/componentRegistry'
import { martisEventBus } from '@/lib/eventBus'

const action: ActionMeta = {
  uriKey: 'issue-token', name: 'Issue token', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: false,
  executionMode: 'bulk', standalone: false, sole: false, queued: false, withConfirmation: true,
  confirmText: null, confirmButtonText: 'Run', cancelButtonText: null, modalSize: 'md',
  supportsDryRun: true, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: false, pivotLabel: null,
}

function TokenModal({ data, onClose }: ActionResponseModalProps) {
  return (
    <div role="dialog">
      <p>Token: {String(data.token)}</p>
      <button type="button" onClick={onClose}>Done</button>
    </div>
  )
}

function Probe() {
  const location = useLocation()
  return <p>At {location.pathname}{location.search}</p>
}

function renderInShell(node: ReactElement) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/']}>
        <ActionResponseModalProvider>
          <Routes>
            <Route path="/" element={node} />
            <Route path="/resources/users" element={<Probe />} />
          </Routes>
        </ActionResponseModalProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
  apiGetMock.mockResolvedValue({ data: { fields: [] } })
  componentRegistry.register('generated-token', TokenModal)
  martisEventBus.clear('martis:action-executed')
})

afterEach(() => componentRegistry.unregister('generated-token'))

describe('action answers', () => {
  it('shows the component a modal answer names, and refreshes once when it closes', async () => {
    apiPostMock.mockResolvedValue({ data: { type: 'modal', data: { component: 'generated-token', data: { token: 's3cret' } } } })
    const onHide = vi.fn()
    const onSuccess = vi.fn()
    renderInShell(<ActionModal resource="users" action={action} selectedIds={[3]} visible onHide={onHide} onSuccess={onSuccess} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Run' }))

    expect(await screen.findByText('Token: s3cret')).toBeTruthy()
    expect(onHide).toHaveBeenCalled()
    expect(onSuccess).not.toHaveBeenCalled()
    fireEvent.click(screen.getByRole('button', { name: 'Done' }))
    expect(onSuccess).toHaveBeenCalledTimes(1)
    expect(screen.queryByText('Token: s3cret')).toBeNull()
  })

  it('navigates inside the SPA on a visit answer', async () => {
    apiPostMock.mockResolvedValue({ data: { type: 'visit', data: { path: '/resources/users', params: { view: 'open' } } } })
    renderInShell(<ActionModal resource="users" action={action} selectedIds={[3]} visible onHide={() => {}} onSuccess={() => {}} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Run' }))

    expect(await screen.findByText('At /resources/users?view=open')).toBeTruthy()
  })

  it('emits martis:action-executed once per run, and never for a dry run', async () => {
    apiPostMock.mockImplementation(async (_url: string, body: { dryRun?: boolean }) =>
      body.dryRun ? { data: { preview: 'Would issue 2 tokens.' } } : { data: { type: 'message', data: { message: 'Issued.' } } },
    )
    const listener = vi.fn()
    martisEventBus.on('martis:action-executed', listener)
    renderInShell(<ActionModal resource="users" action={action} selectedIds={[3, 5]} visible onHide={() => {}} onSuccess={() => {}} />)

    fireEvent.click(await screen.findByRole('button', { name: /preview/i }))
    expect(await screen.findByText('Would issue 2 tokens.')).toBeTruthy()
    expect(listener).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Run' }))
    await waitFor(() => expect(listener).toHaveBeenCalledTimes(1))
    expect(listener).toHaveBeenCalledWith({ resourceKey: 'users', action: 'issue-token', ids: [3, 5] })
  })

  it('runs a pivot action answer through the same dispatcher', async () => {
    apiPostMock.mockResolvedValue({ data: { type: 'modal', data: { component: 'generated-token', data: { token: 'pivot' } } } })
    const onClose = vi.fn()
    const onSuccess = vi.fn()
    renderInShell(
      <PivotActionModal
        actionsUrl="/api/resources/users/7/belongs-to-many/roles/actions"
        resourceKey="roles"
        action={{ ...action, isPivotAction: true, supportsDryRun: false }}
        selectedIds={[3]}
        onSuccess={onSuccess}
        onClose={onClose}
      />,
    )

    fireEvent.click(await screen.findByRole('button', { name: 'Run' }))

    expect(await screen.findByText('Token: pivot')).toBeTruthy()
    expect(onClose).toHaveBeenCalled()
    fireEvent.click(screen.getByRole('button', { name: 'Done' }))
    expect(onSuccess).toHaveBeenCalledTimes(1)
  })
})
