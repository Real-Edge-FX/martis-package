import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/contexts/ToastContext'
import { ApiError } from '@/lib/api'

/*
 * The inline-create modal shows the errors of the fields it renders under
 * each field and folds every other error into one general message. An error
 * on a Repeater row (`lines.0.fields.name`) matched no rendered attribute,
 * so it landed in the general message instead of under the row field.
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

import { InlineCreateModal } from './InlineCreateModal'
import { registerDefaultFields } from './fields/FieldRenderer'

registerDefaultFields()

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
  apiGetMock.mockResolvedValue({ data: { fields: [linesField], singularLabel: 'Order', label: 'Orders' } })
  apiPostMock.mockRejectedValue(new ApiError(422, 'The given data was invalid.', [
    { field: 'lines.0.fields.name', message: required, code: 'required' },
  ]))
})

describe('InlineCreateModal Repeater errors', () => {
  it('shows a row error under the row field instead of in the general message', async () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ToastProvider>
          <MemoryRouter>
            <InlineCreateModal relatedResource="orders" open onClose={() => {}} onCreated={() => {}} />
          </MemoryRouter>
        </ToastProvider>
      </QueryClientProvider>,
    )

    // The first render loads the schema and every field module: allow a cold start.
    fireEvent.click(await screen.findByRole('button', { name: 'Add Line' }, { timeout: 4000 }))
    fireEvent.submit(document.getElementById('inline-create-form') as HTMLFormElement)

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    const row = document.body.querySelector('[draggable]') as HTMLElement
    await waitFor(() => expect(within(row).getByText(required)).toBeTruthy())
    expect(screen.getAllByText(required)).toHaveLength(1)
  }, 10000)
})
