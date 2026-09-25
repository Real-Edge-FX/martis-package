import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { OverrideProps, ResourceRecord, ResourceSchema } from '@/types'
import { api, ApiError } from '@/lib/api'

/*
 * The create and update drawers handed each input `errors[attribute]` only,
 * so the error of a Repeater row field (`lines.1.fields.name`) never reached
 * the row. They now pass each input the errors inside its value too.
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), upload: vi.fn() } }
})

// The shell's portal, focus handling and animation are not under test.
vi.mock('./DrawerShell', () => ({
  DrawerShell: ({ children, footer }: { children: ReactNode; footer?: ReactNode }) => (
    <div>
      {children}
      {footer}
    </div>
  ),
}))

import { DrawerCreate } from './DrawerCreate'
import { DrawerUpdate } from './DrawerUpdate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

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

const rejection = (field: string) => new ApiError(422, 'The given data was invalid.', [
  { field, message: required, code: 'required' },
])

function drawerProps(record: ResourceRecord | null): OverrideProps {
  return {
    schema: {
      uriKey: 'orders',
      label: 'Orders',
      singularLabel: 'Order',
      softDeletes: false,
      confirmUnsavedChanges: false,
      fieldsForCreate: [linesField],
      fieldsForUpdate: [linesField],
    } as unknown as ResourceSchema,
    resource: 'orders',
    params: {},
    record,
    recordId: record ? '5' : null,
    navigate: vi.fn(),
    onClose: vi.fn(),
    onCreated: vi.fn(),
    onUpdated: vi.fn(),
    onDeleted: vi.fn(),
    onEdit: vi.fn(),
    onView: vi.fn(),
    addToast: vi.fn(),
  }
}

function renderDrawer(drawer: ReactNode) {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter>{drawer}</MemoryRouter>
    </QueryClientProvider>,
  )
}

const rowsOf = () => [...document.querySelectorAll('[draggable]')] as HTMLElement[]

beforeEach(() => {
  vi.mocked(api.post).mockReset()
  vi.mocked(api.put).mockReset()
})

describe('record drawers with a Repeater', () => {
  it('DrawerCreate shows a row error under the row field', async () => {
    vi.mocked(api.post).mockRejectedValue(rejection('lines.0.fields.name'))
    renderDrawer(<DrawerCreate {...drawerProps(null)} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Add Line' }, { timeout: 4000 }))
    fireEvent.submit(document.getElementById('martis-drawer-create-form') as HTMLFormElement)

    await waitFor(() => expect(within(rowsOf()[0]).getByText(required)).toBeTruthy())
  }, 10000)

  it('DrawerUpdate shows a row error under the row field of the row it belongs to', async () => {
    vi.mocked(api.put).mockRejectedValue(rejection('lines.1.fields.name'))
    const record = {
      id: 5,
      lines: [
        { id: 'a', type: 'line', fields: { name: 'First' } },
        { id: 'b', type: 'line', fields: { name: '' } },
      ],
    } as unknown as ResourceRecord
    renderDrawer(<DrawerUpdate {...drawerProps(record)} />)

    await screen.findByDisplayValue('First', {}, { timeout: 4000 })
    fireEvent.submit(document.getElementById('martis-drawer-update-form') as HTMLFormElement)

    await waitFor(() => expect(within(rowsOf()[1]).getByText(required)).toBeTruthy())
    expect(within(rowsOf()[0]).queryByText(required)).toBeNull()
  }, 10000)
})
