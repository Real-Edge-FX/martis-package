import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * A picker open in the action form (a MultiSelect's list) is a layer over
 * the modal: Escape closes the list and leaves the modal open, and a second
 * Escape closes the modal. The modal listened on document before the picker
 * did, so one Escape closed both and the form was lost. A click on an
 * option, which renders in document.body, does not close the modal either.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args), post: vi.fn() },
  }
})

import { ActionModal, type ActionMeta } from './ActionModal'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

const action: ActionMeta = {
  uriKey: 'invite', name: 'Invite user', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: false,
  executionMode: 'bulk', standalone: true, sole: false, queued: false, withConfirmation: true,
  confirmText: null, confirmButtonText: null, cancelButtonText: null, modalSize: 'md',
  supportsDryRun: false, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: false, pivotLabel: null,
}

const rolesField = {
  attribute: 'roles', label: 'Roles', type: 'multi_select', component: 'multi_select',
  options: [
    { label: 'Viewer', value: 'viewer' },
    { label: 'Full Access', value: 'full' },
  ],
  nullable: true, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
}

const panel = () => document.body.querySelector('[data-martis-floating-panel]')

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockImplementation(async () => ({ data: { fields: [rolesField] } }))
})

describe('ActionModal with an open picker', () => {
  it('closes the list on the first Escape and the modal on the second', async () => {
    const onHide = vi.fn()
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ToastProvider>
          <MemoryRouter>
            <ActionModal resource="users" action={action} selectedIds={[]} visible onHide={onHide} onSuccess={() => {}} />
          </MemoryRouter>
        </ToastProvider>
      </QueryClientProvider>,
    )

    const trigger = await waitFor(() => {
      const el = document.body.querySelector('[role="dialog"] .martis-input')
      expect(el).not.toBeNull()
      return el as HTMLElement
    })

    fireEvent.click(trigger)
    expect(panel()).not.toBeNull()
    expect(document.body.querySelector('[role="dialog"]')!.contains(panel())).toBe(false)

    const option = Array.from(panel()!.querySelectorAll('button')).find((b) => b.textContent?.includes('Full Access'))!
    fireEvent.mouseDown(option)
    fireEvent.click(option)
    expect(onHide).not.toHaveBeenCalled()
    expect(panel()).not.toBeNull()

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(panel()).toBeNull()
    expect(onHide).not.toHaveBeenCalled()

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(onHide).toHaveBeenCalledTimes(1)
  })

  function mountWith(fields: unknown[], onHide: () => void) {
    apiGetMock.mockImplementation(async () => ({ data: { fields } }))
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ToastProvider>
          <MemoryRouter>
            <ActionModal resource="users" action={action} selectedIds={[]} visible onHide={onHide} onSuccess={() => {}} />
          </MemoryRouter>
        </ToastProvider>
      </QueryClientProvider>,
    )
  }

  const statusField = {
    attribute: 'status', label: 'Status', type: 'select', component: 'select',
    options: [{ label: 'Active', value: 'a' }, { label: 'Paused', value: 'p' }],
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
  }

  async function dropdownInput(): Promise<HTMLElement> {
    return waitFor(() => {
      const el = document.body.querySelector('[role="dialog"] .p-dropdown input') as HTMLElement | null
      expect(el).not.toBeNull()
      return el as HTMLElement
    })
  }

  // PrimeReact's Dropdown marks every Escape it sees handled, its list open or not.
  it('closes on one Escape while focus is in a PrimeReact Dropdown whose list is closed', async () => {
    const onHide = vi.fn()
    mountWith([statusField], onHide)
    const input = await dropdownInput()
    input.focus()

    fireEvent.keyDown(input, { key: 'Escape', code: 'Escape' })
    expect(onHide).toHaveBeenCalledTimes(1)
  })

  it('leaves the Escape to an open PrimeReact Dropdown list', async () => {
    const onHide = vi.fn()
    mountWith([statusField], onHide)
    const input = await dropdownInput()
    fireEvent.click(input.closest('.p-dropdown') as HTMLElement)
    await waitFor(() => expect(document.body.querySelector('.p-dropdown-panel')).not.toBeNull())

    fireEvent.keyDown(input, { key: 'Escape', code: 'Escape' })
    expect(onHide).not.toHaveBeenCalled()
  })
})
