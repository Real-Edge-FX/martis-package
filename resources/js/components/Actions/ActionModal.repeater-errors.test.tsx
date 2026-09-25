import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/contexts/ToastContext'
import { ApiError } from '@/lib/api'

/*
 * The server validates the rows of a Repeater among an Action's fields and
 * answers `lines.0.fields.name` for a row field. The modal handed each input
 * `fieldErrors[attribute]` only, so the error never showed; it now reaches
 * the row field, in the resource action modal and in the pivot action modal
 * of a many-to-many panel. Each run starts without the errors of the last
 * one.
 */

const apiGetMock = vi.fn()
const apiPostMock = vi.fn()

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

import { ActionModal, type ActionMeta } from './ActionModal'
import { PivotActionModal } from '@/components/fields/relation/PivotActionModal'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

const action: ActionMeta = {
  uriKey: 'import-lines', name: 'Import lines', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: false,
  executionMode: 'standalone', standalone: true, sole: false, queued: false, withConfirmation: true,
  confirmText: null, confirmButtonText: 'Import', cancelButtonText: null, modalSize: 'md',
  supportsDryRun: false, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: false, pivotLabel: null,
}

const linesField = {
  attribute: 'lines', label: 'Lines', type: 'repeater',
  nullable: true, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [], storage: 'json',
  repeatables: [{
    shortName: 'line', uniqueKey: 'line', label: 'Line',
    fields: [{ attribute: 'name', label: 'Name', type: 'text', nullable: true, readonly: false, required: false, rules: [] }],
  }],
}

const required = 'The Name field is required.'

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
  apiGetMock.mockImplementation(async (url: string) =>
    url.endsWith('/import-lines/fields') ? { data: { fields: [linesField] } } : { data: [] },
  )
  apiPostMock.mockRejectedValue(new ApiError(422, 'The given data was invalid.', [
    { field: 'lines.0.fields.name', message: required, code: 'required' },
  ]))
})

describe('ActionModal Repeater errors', () => {
  it('shows a row error under the row field and clears the errors when it runs again', async () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ToastProvider>
          <MemoryRouter>
            <ActionModal resource="orders" action={action} selectedIds={[]} visible onHide={() => {}} onSuccess={() => {}} />
          </MemoryRouter>
        </ToastProvider>
      </QueryClientProvider>,
    )

    // The first render loads the fields and every field module: allow a cold start.
    fireEvent.click(await screen.findByRole('button', { name: 'Add Line' }, { timeout: 4000 }))
    fireEvent.click(screen.getByRole('button', { name: 'Import' }))

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    const row = document.body.querySelector('[draggable]') as HTMLElement
    await waitFor(() => expect(within(row).getByText(required)).toBeTruthy())

    apiPostMock.mockReturnValue(new Promise(() => {}))
    fireEvent.click(screen.getByRole('button', { name: 'Import' }))

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(2))
    expect(within(row).queryByText(required)).toBeNull()
  }, 10000)
})

describe('PivotActionModal Repeater errors', () => {
  it('shows a row error under the row field and clears the errors when it runs again', async () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ToastProvider>
          <MemoryRouter>
            <PivotActionModal
              actionsUrl="/api/resources/projects/7/belongs-to-many/members/actions"
              action={{ ...action, isPivotAction: true }}
              selectedIds={[1]}
              onSuccess={() => {}}
              onClose={() => {}}
            />
          </MemoryRouter>
        </ToastProvider>
      </QueryClientProvider>,
    )

    // The first render loads the fields and every field module: allow a cold start.
    fireEvent.click(await screen.findByRole('button', { name: 'Add Line' }, { timeout: 4000 }))
    fireEvent.click(screen.getByRole('button', { name: 'Import' }))

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    const row = document.body.querySelector('[draggable]') as HTMLElement
    await waitFor(() => expect(within(row).getByText(required)).toBeTruthy())

    apiPostMock.mockReturnValue(new Promise(() => {}))
    fireEvent.click(screen.getByRole('button', { name: 'Import' }))

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(2))
    expect(within(row).queryByText(required)).toBeNull()
  }, 10000)
})
