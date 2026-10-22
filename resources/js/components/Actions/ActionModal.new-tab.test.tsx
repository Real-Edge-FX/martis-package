import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactElement } from 'react'

// Cato report (2026-10-22): an openInNewTab answer opened its tab after the
// request returned, so the browser blocked it. An action that declares
// opensInNewTab() now opens a blank tab on the click (Run Action, or the
// click that runs an action without a modal) and the answer sends it to the
// URL; an error or another answer closes it. And a confirmation modal of an
// action with no confirmText() shows Nova's default sentence, not a blank body.

const apiGetMock = vi.fn()
const apiPostMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args), post: (...args: unknown[]) => apiPostMock(...args) } }
})
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))
vi.mock('@/lib/historyLock', () => ({ useModalHistoryLock: () => undefined }))

import { ActionModal, type ActionMeta } from './ActionModal'
import { ActionResponseModalProvider } from './ActionResponseModalHost'
import { PivotActionModal } from '@/components/fields/relation/PivotActionModal'

const action: ActionMeta = {
  uriKey: 'issue-token', name: 'Issue token', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: false,
  executionMode: 'bulk', standalone: false, sole: false, queued: false, withConfirmation: true,
  confirmText: null, confirmButtonText: 'Run', cancelButtonText: null, modalSize: 'md',
  supportsDryRun: true, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: false, pivotLabel: null,
}

function renderModal(node: ReactElement) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <ActionResponseModalProvider>{node}</ActionResponseModalProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function blankTab() {
  return { opener: {} as unknown, closed: false, close: vi.fn(), location: { replace: vi.fn() } }
}

let tab: ReturnType<typeof blankTab>
let openSpy: ReturnType<typeof vi.spyOn>

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
  apiGetMock.mockResolvedValue({ data: { fields: [] } })
  tab = blankTab()
  openSpy = vi.spyOn(window, 'open').mockImplementation(() => tab as unknown as Window)
})

afterEach(() => vi.restoreAllMocks())

const STRIPE = 'https://dashboard.stripe.com/customers/cus_1'

describe('an opensInNewTab() action', () => {
  it('opens a blank tab on Run Action, before the request, and sends it to the answer\'s URL', async () => {
    let resolve: (value: unknown) => void = () => {}
    apiPostMock.mockReturnValue(new Promise((r) => { resolve = r }))
    renderModal(<ActionModal resource="users" action={{ ...action, opensInNewTab: true }} selectedIds={[3]} visible onHide={vi.fn()} onSuccess={vi.fn()} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Run' }))

    expect(openSpy).toHaveBeenCalledTimes(1)
    expect(openSpy).toHaveBeenCalledWith('', '_blank')
    expect(tab.opener).toBeNull()

    resolve({ data: { type: 'openInNewTab', data: { url: STRIPE } } })
    await waitFor(() => expect(tab.location.replace).toHaveBeenCalledWith(STRIPE))
    expect(openSpy).toHaveBeenCalledTimes(1)
    expect(tab.close).not.toHaveBeenCalled()
  })

  it('closes the blank tab when the run fails', async () => {
    apiPostMock.mockRejectedValue(new Error('boom'))
    renderModal(<ActionModal resource="users" action={{ ...action, opensInNewTab: true }} selectedIds={[3]} visible onHide={vi.fn()} onSuccess={vi.fn()} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Run' }))

    await waitFor(() => expect(tab.close).toHaveBeenCalled())
    expect(tab.location.replace).not.toHaveBeenCalled()
  })

  it('closes the blank tab when the run answers something else', async () => {
    apiPostMock.mockResolvedValue({ data: { type: 'message', data: { message: 'Done' } } })
    renderModal(<ActionModal resource="users" action={{ ...action, opensInNewTab: true }} selectedIds={[3]} visible onHide={vi.fn()} onSuccess={vi.fn()} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Run' }))

    await waitFor(() => expect(tab.close).toHaveBeenCalled())
  })

  it('opens the tab in the render the click caused when the action has no modal', async () => {
    apiPostMock.mockResolvedValue({ data: { type: 'openInNewTab', data: { url: STRIPE } } })
    renderModal(
      <ActionModal
        resource="users"
        action={{ ...action, opensInNewTab: true, withConfirmation: false, supportsDryRun: false }}
        selectedIds={[3]}
        visible
        onHide={vi.fn()}
        onSuccess={vi.fn()}
      />,
    )

    await waitFor(() => expect(tab.location.replace).toHaveBeenCalledWith(STRIPE))
    // Once: an open-time reset of the auto-execute guard used to run it twice.
    expect(apiPostMock).toHaveBeenCalledTimes(1)
    expect(openSpy).toHaveBeenCalledTimes(1)
    expect(openSpy).toHaveBeenCalledWith('', '_blank')
  })

  it('opens the tab on Run Action of a pivot action too', async () => {
    apiPostMock.mockResolvedValue({ data: { type: 'openInNewTab', data: { url: STRIPE } } })
    renderModal(
      <PivotActionModal
        action={{ ...action, opensInNewTab: true, isPivotAction: true }}
        actionsUrl="/api/resources/users/1/roles/pivot-actions"
        resourceKey="users"
        selectedIds={[3]}
        onClose={vi.fn()}
        onSuccess={vi.fn()}
      />,
    )

    fireEvent.click(await screen.findByRole('button', { name: 'Run' }))

    await waitFor(() => expect(tab.location.replace).toHaveBeenCalledWith(STRIPE))
    expect(openSpy).toHaveBeenCalledWith('', '_blank')
  })
})

describe('an action without opensInNewTab()', () => {
  it('opens no blank tab on Run Action', async () => {
    apiPostMock.mockResolvedValue({ data: { type: 'message', data: { message: 'Done' } } })
    renderModal(<ActionModal resource="users" action={action} selectedIds={[3]} visible onHide={vi.fn()} onSuccess={vi.fn()} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Run' }))

    await waitFor(() => expect(apiPostMock).toHaveBeenCalled())
    expect(openSpy).not.toHaveBeenCalled()
  })
})

describe('the confirmation text', () => {
  it('falls back to the default sentence when the action sets no confirmText()', async () => {
    renderModal(<ActionModal resource="users" action={action} selectedIds={[3]} visible onHide={vi.fn()} onSuccess={vi.fn()} />)

    expect(await screen.findByText('confirm_run')).toBeTruthy()
  })

  it('shows the action\'s own confirmText()', async () => {
    renderModal(<ActionModal resource="users" action={{ ...action, confirmText: 'Open the customer in Stripe?' }} selectedIds={[3]} visible onHide={vi.fn()} onSuccess={vi.fn()} />)

    expect(await screen.findByText('Open the customer in Stripe?')).toBeTruthy()
    expect(screen.queryByText('confirm_run')).toBeNull()
  })
})

describe('an action without a modal', () => {
  it('runs once, also from a pivot panel', async () => {
    apiPostMock.mockResolvedValue({ data: { type: 'message', data: { message: 'Done' } } })
    const quiet = { ...action, withConfirmation: false, supportsDryRun: false }
    renderModal(<ActionModal resource="users" action={quiet} selectedIds={[3]} visible onHide={vi.fn()} onSuccess={vi.fn()} />)
    await waitFor(() => expect(apiPostMock).toHaveBeenCalled())
    await new Promise((r) => setTimeout(r, 50))
    expect(apiPostMock).toHaveBeenCalledTimes(1)

    apiPostMock.mockClear()
    renderModal(
      <PivotActionModal action={{ ...quiet, isPivotAction: true }} actionsUrl="/api/resources/users/1/roles/pivot-actions" resourceKey="users" selectedIds={[3]} onClose={vi.fn()} onSuccess={vi.fn()} />,
    )
    await waitFor(() => expect(apiPostMock).toHaveBeenCalled())
    await new Promise((r) => setTimeout(r, 50))
    expect(apiPostMock).toHaveBeenCalledTimes(1)
  })
})
