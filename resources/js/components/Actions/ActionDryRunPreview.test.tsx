import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

/*
 * Dry run in the action modals. `withDryRun()` shows a Preview button that
 * posts `dryRun: true`; the server answers `{ preview }` from the action's
 * dryRun() without running handle(). The resource modal read that answer as
 * a finished run (success toast, modal closed, list refreshed) and never
 * showed the preview; the pivot action modal had no Preview button at all,
 * and an action with no fields and no confirmation ran before Preview could
 * be offered.
 */

const apiGetMock = vi.fn()
const apiPostMock = vi.fn()
const addToastMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: (...args: unknown[]) => apiPostMock(...args),
    },
  }
})

vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: addToastMock }) }))
vi.mock('@/lib/historyLock', () => ({ useModalHistoryLock: () => undefined }))

import { ActionModal, type ActionMeta } from './ActionModal'
import { PivotActionModal } from '@/components/fields/relation/PivotActionModal'

const action: ActionMeta = {
  uriKey: 'archive-posts', name: 'Archive posts', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: false,
  executionMode: 'bulk', standalone: false, sole: false, queued: false, withConfirmation: false,
  confirmText: null, confirmButtonText: 'Run', cancelButtonText: null, modalSize: 'md',
  supportsDryRun: true, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: false, pivotLabel: null,
}

const preview = { preview: 'Would archive 2 post(s).', affected_ids: [3, 5] }

function renderWithProviders(node: React.ReactElement) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{node}</MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
  addToastMock.mockReset()
  apiGetMock.mockResolvedValue({ data: { fields: [] } })
  apiPostMock.mockImplementation(async (_url: string, body: { dryRun?: boolean }) =>
    body.dryRun
      ? { data: { preview } }
      : { data: { type: 'message', data: { message: 'Archived.' } } },
  )
})

describe('ActionModal dry run', () => {
  it('shows the preview in the modal and keeps it open, then runs on confirm', async () => {
    const onHide = vi.fn()
    const onSuccess = vi.fn()
    renderWithProviders(
      <ActionModal resource="posts" action={action} selectedIds={[3, 5]} visible onHide={onHide} onSuccess={onSuccess} />,
    )

    fireEvent.click(await screen.findByRole('button', { name: /preview/i }))

    expect(await screen.findByText('Would archive 2 post(s).')).toBeTruthy()
    expect(screen.getByText('[3,5]')).toBeTruthy()
    expect(apiPostMock).toHaveBeenCalledWith('/api/resources/posts/actions/archive-posts', expect.objectContaining({ dryRun: true }))
    expect(onHide).not.toHaveBeenCalled()
    expect(onSuccess).not.toHaveBeenCalled()
    expect(addToastMock).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Run' }))

    await waitFor(() => expect(onSuccess).toHaveBeenCalled())
    expect(apiPostMock).toHaveBeenLastCalledWith('/api/resources/posts/actions/archive-posts', expect.objectContaining({ dryRun: false }))
  })
})

describe('PivotActionModal dry run', () => {
  const actionsUrl = '/api/resources/users/7/belongs-to-many/roles/actions'

  it('offers Preview for a dry-run action and shows the preview without running it', async () => {
    const onSuccess = vi.fn()
    renderWithProviders(
      <PivotActionModal actionsUrl={actionsUrl} action={{ ...action, isPivotAction: true }} selectedIds={[3, 5]} onSuccess={onSuccess} onClose={() => {}} />,
    )

    fireEvent.click(await screen.findByRole('button', { name: /preview/i }))

    expect(await screen.findByText('Would archive 2 post(s).')).toBeTruthy()
    expect(apiPostMock).toHaveBeenCalledWith(`${actionsUrl}/archive-posts`, expect.objectContaining({ resources: [3, 5], dryRun: true }))
    expect(onSuccess).not.toHaveBeenCalled()
    expect(addToastMock).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Run' }))
    await waitFor(() => expect(onSuccess).toHaveBeenCalled())
  })

  it('has no Preview button when the action does not support a dry run', async () => {
    renderWithProviders(
      <PivotActionModal
        actionsUrl={actionsUrl}
        action={{ ...action, supportsDryRun: false, withConfirmation: true }}
        selectedIds={[3]}
        onSuccess={() => {}}
        onClose={() => {}}
      />,
    )

    expect(await screen.findByRole('button', { name: 'Run' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: /preview/i })).toBeNull()
  })
})
