import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/contexts/ToastContext'

const apiGetMock = vi.fn()
const apiPostMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...a: unknown[]) => apiGetMock(...a), post: (...a: unknown[]) => apiPostMock(...a) },
  }
})

import { PivotActionModal } from './PivotActionModal'
import type { ActionMeta } from '@/components/Actions/ActionModal'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

const action: ActionMeta = {
  uriKey: 'note', name: 'Note', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: false,
  executionMode: 'bulk', standalone: false, sole: false, queued: false, withConfirmation: true,
  confirmText: null, confirmButtonText: null, cancelButtonText: null, modalSize: 'md',
  supportsDryRun: false, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: true, pivotLabel: null,
}

const noteField = {
  attribute: 'note', label: 'Note', type: 'text', defaultValue: 'abc', helpText: 'A short note', tooltip: 'Tip',
  nullable: true, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
}

const actionsUrl = '/api/resources/projects/7/belongs-to-many/members/actions'

function mount(selectedIds: Array<string | number>) {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <ToastProvider>
        <MemoryRouter>
          <PivotActionModal actionsUrl={actionsUrl} resourceKey="users" action={action} selectedIds={selectedIds} onSuccess={() => {}} onClose={() => {}} />
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
  apiPostMock.mockResolvedValue({ data: { type: 'message', data: { message: 'Done.' } } })
  apiGetMock.mockImplementation(async () => ({ data: { fields: [noteField] } }))
})

describe('PivotActionModal defaults, help and record', () => {
  it('seeds the default, sends it, and sends an edit instead', async () => {
    mount([4])
    const input = () => document.body.querySelector('#note') as HTMLInputElement
    await waitFor(() => expect(input()?.value).toBe('abc'))
    fireEvent.click(document.body.querySelector('.martis-btn-primary') as HTMLElement)
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    expect(apiPostMock.mock.calls[0][1].fields).toEqual({ note: 'abc' })

    apiPostMock.mockClear()
    fireEvent.change(input(), { target: { value: 'xyz' } })
    fireEvent.click(document.body.querySelector('.martis-btn-primary') as HTMLElement)
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    expect(apiPostMock.mock.calls[0][1].fields).toEqual({ note: 'xyz' })
  })

  it('renders the help tied to the input, and the tooltip', async () => {
    mount([4])
    await waitFor(() => expect(document.body.querySelector('#note')).not.toBeNull())
    const help = document.body.querySelector('.martis-input-help') as HTMLElement
    expect(help.textContent).toBe('A short note')
    await waitFor(() => expect(document.body.querySelector('#note')?.getAttribute('aria-describedby')).toBe(help.id))
    expect(document.body.querySelector('[aria-label="Tip"]')).not.toBeNull()
  })

  it('names the record on the fields request for one record only', async () => {
    mount([4])
    await waitFor(() => expect(apiGetMock).toHaveBeenCalled())
    expect(String(apiGetMock.mock.calls[0][0])).toBe(`${actionsUrl}/note/fields?resourceId=4`)
  })

  it('sends no record for several', async () => {
    mount([4, 5])
    await waitFor(() => expect(apiGetMock).toHaveBeenCalled())
    expect(String(apiGetMock.mock.calls[0][0])).toBe(`${actionsUrl}/note/fields`)
  })
})
