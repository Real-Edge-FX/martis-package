import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import type { FieldDefinition, OverrideProps, PanelDefinition, ResourceRecord, ResourceSchema } from '@/types'

/*
 * The update drawer renders the resource's `fieldsForUpdate` against the
 * record it edits, which lists under `_hidden` the fields
 * `canSeeForModel()` hides for it (v1.38.0). The drawer leaves those fields
 * out, whether the host hands it the record or it loads the record itself.
 */

const apiGetMock = vi.fn()
const apiPutMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      put: (...args: unknown[]) => apiPutMock(...args),
    },
  }
})

// DrawerShell renders children + footer into the DOM so assertions work.
vi.mock('./DrawerShell', () => ({
  DrawerShell: ({ children, footer }: { children: React.ReactNode; footer?: React.ReactNode }) => (
    <div>
      <div data-testid="drawer-content">{children}</div>
      <div data-testid="drawer-footer">{footer}</div>
    </div>
  ),
}))

import { DrawerUpdate } from './DrawerUpdate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function field(attribute: string, label: string, type = 'text'): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
  } as unknown as FieldDefinition
}

function panel(title: string, fields: FieldDefinition[]): PanelDefinition {
  return { type: 'panel', title, description: null, fields, collapsible: false, collapsedByDefault: false, limit: null }
}

const schema = {
  uriKey: 'employees', label: 'Employees', singularLabel: 'Employee', fields: [],
  fieldsForUpdate: [
    field('title', 'Title'),
    field('salary', 'Salary'),
    field('active', 'Active', 'boolean'),
    panel('Pay', [field('bonus', 'Bonus')]),
  ],
  errorDisplay: 'inline', confirmUnsavedChanges: false,
} as unknown as ResourceSchema

function renderDrawer(record: ResourceRecord | null) {
  const props: OverrideProps = {
    schema,
    resource: 'employees',
    params: {},
    record,
    recordId: '1',
    navigate: vi.fn(),
    onClose: vi.fn(),
    onCreated: vi.fn(),
    onUpdated: vi.fn(),
    onDeleted: vi.fn(),
    onEdit: vi.fn(),
    onView: vi.fn(),
    addToast: vi.fn(),
  }
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
      <MemoryRouter>
        <DrawerUpdate {...props} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const hiding = { id: 1, title: 'Existing', _hidden: ['salary', 'active', 'bonus'] } as unknown as ResourceRecord

beforeEach(() => {
  apiGetMock.mockReset()
  apiPutMock.mockReset()
  apiPutMock.mockReturnValue(new Promise(() => {}))
})

describe('DrawerUpdate — the fields a record hides', () => {
  it('leaves the fields the handed record hides out of the form and of the save', async () => {
    renderDrawer(hiding)

    await waitFor(() => expect((document.getElementById('title') as HTMLInputElement | null)?.value).toBe('Existing'))

    expect(document.getElementById('salary')).toBeNull()
    for (const label of ['Salary', 'Active', 'Pay', 'Bonus']) {
      expect(screen.queryByText(label)).toBeNull()
    }

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(apiPutMock).toHaveBeenCalled())
    const [, body] = apiPutMock.mock.calls[0] as [string, Record<string, unknown>]
    expect(Object.keys(body)).toEqual(['title'])
  })

  it('leaves out the fields the record it loads hides', async () => {
    apiGetMock.mockImplementation((path: string) =>
      path === '/api/resources/employees/1?context=update' ? Promise.resolve({ data: hiding }) : Promise.resolve({ data: [] }),
    )

    renderDrawer(null)

    await waitFor(() => expect((document.getElementById('title') as HTMLInputElement | null)?.value).toBe('Existing'))
    expect(document.getElementById('salary')).toBeNull()
    expect(screen.queryByText('Pay')).toBeNull()
  })

  it('renders every field of a record that hides none', async () => {
    renderDrawer({ id: 1, title: 'Existing', salary: '100', active: true, bonus: '10' } as unknown as ResourceRecord)

    await waitFor(() => expect((document.getElementById('salary') as HTMLInputElement | null)?.value).toBe('100'))
    expect(screen.getByText('Pay')).toBeTruthy()
  })
})
