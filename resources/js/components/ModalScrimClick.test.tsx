import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * The modals that hold a form (an action's, a pivot action's, an inline
 * create's) closed on a click on the scrim around them, and a stray click
 * lost everything typed in the form. They now close only through Cancel,
 * the X and Escape.
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: vi.fn(async () => ({ data: { fields: [] } })), post: vi.fn() },
  }
})

import { ActionModal, type ActionMeta } from './Actions/ActionModal'
import { PivotActionModal } from './fields/relation/PivotActionModal'
import { InlineCreateModal } from './InlineCreateModal'

const action: ActionMeta = {
  uriKey: 'invite', name: 'Invite user', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: false,
  executionMode: 'bulk', standalone: true, sole: false, queued: false, withConfirmation: true,
  confirmText: null, confirmButtonText: null, cancelButtonText: null, modalSize: 'md',
  supportsDryRun: false, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: false, pivotLabel: null,
}

function mount(node: React.ReactNode) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <ToastProvider>
        <MemoryRouter>{node}</MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

async function clickScrim() {
  const scrim = await waitFor(() => {
    const el = document.body.querySelector('.martis-modal-scrim')
    expect(el?.querySelector('[role="dialog"]')).toBeTruthy()
    return el as HTMLElement
  })
  fireEvent.mouseDown(scrim)
  fireEvent.click(scrim)
  await new Promise((r) => setTimeout(r, 250))
}

describe('a click on the scrim of a form modal', () => {
  it('does not close an action modal', async () => {
    const onHide = vi.fn()
    mount(<ActionModal resource="users" action={action} selectedIds={[]} visible onHide={onHide} onSuccess={() => {}} />)
    await clickScrim()
    expect(onHide).not.toHaveBeenCalled()
  })

  it('does not close a pivot action modal', async () => {
    const onClose = vi.fn()
    mount(
      <PivotActionModal
        actionsUrl="/api/x" resourceKey="users" action={{ ...action, isPivotAction: true, standalone: false }}
        selectedIds={[1]} onSuccess={() => {}} onClose={onClose}
      />,
    )
    await clickScrim()
    expect(onClose).not.toHaveBeenCalled()
  })

  it('does not close an inline create modal', async () => {
    const onClose = vi.fn()
    mount(<InlineCreateModal relatedResource="clients" open onClose={onClose} onCreated={() => {}} />)
    await clickScrim()
    expect(onClose).not.toHaveBeenCalled()
  })
})
