import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition, OverrideProps, PanelDefinition, ResourceRecord, ResourceSchema } from '@/types'

/*
 * The detail drawer renders the resource's `fieldsForDetail` against the
 * record, which lists under `_hidden` the fields `canSeeForModel()` hides for
 * it (v1.38.0): the drawer leaves them out, as the detail page does, instead
 * of showing them empty.
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: vi.fn(() => new Promise(() => {})), delete: vi.fn() },
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

import { DrawerDetail } from './DrawerDetail'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function field(attribute: string, label: string, type = 'text'): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
  } as unknown as FieldDefinition
}

function panel(title: string, fields: FieldDefinition[]): PanelDefinition {
  return { type: 'panel', title, description: null, fields, collapsible: false, collapsedByDefault: false, limit: null }
}

function renderDrawer(record: Record<string, unknown>) {
  const schema = {
    uriKey: 'employees', label: 'Employees', singularLabel: 'Employee', fields: [],
    fieldsForDetail: [
      field('name', 'Name'),
      field('active', 'Active', 'boolean'),
      panel('Pay', [field('bonus', 'Bonus')]),
    ],
  } as unknown as ResourceSchema
  const props: OverrideProps = {
    schema,
    resource: 'employees',
    params: {},
    record: record as unknown as ResourceRecord,
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
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <DrawerDetail {...props} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('DrawerDetail — the fields a record hides', () => {
  it('leaves out the fields the record hides, and a panel left without fields', () => {
    renderDrawer({ id: 1, _title: 'Ann', name: 'Ann', _hidden: ['active', 'bonus'] })

    expect(screen.getByText('Name')).toBeTruthy()
    for (const label of ['Active', 'No', 'Pay', 'Bonus']) {
      expect(screen.queryByText(label)).toBeNull()
    }
  })

  it('renders every field of a record that hides none', () => {
    renderDrawer({ id: 1, _title: 'Ann', name: 'Ann', active: false, bonus: '10' })

    for (const label of ['Active', 'No', 'Pay', 'Bonus']) {
      expect(screen.getByText(label)).toBeTruthy()
    }
  })
})
