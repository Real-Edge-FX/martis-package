import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition, OverrideProps, ResourceRecord, ResourceSchema } from '@/types'

/*
 * The quick-look drawer renders the resource's preview fields against the
 * record, which lists under `_hidden` the fields `canSeeForModel()` hides
 * for it (v1.38.0): the drawer leaves them out instead of showing them empty.
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: vi.fn(() => new Promise(() => {})) } }
})

vi.mock('./DrawerShell', () => ({
  DrawerShell: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}))

import { DrawerQuick } from './DrawerQuick'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function field(attribute: string, label: string, type = 'text'): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
  } as unknown as FieldDefinition
}

function renderDrawer(record: Record<string, unknown>) {
  const props: OverrideProps = {
    schema: {
      uriKey: 'employees', label: 'Employees', singularLabel: 'Employee', fields: [],
      fieldsForPreview: [field('name', 'Name'), field('active', 'Active', 'boolean')],
    } as unknown as ResourceSchema,
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
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter>
        <DrawerQuick {...props} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('DrawerQuick — the fields a record hides', () => {
  it('leaves out the fields the record hides', () => {
    renderDrawer({ id: 1, name: 'Ann', _hidden: ['active'] })

    expect(screen.getByText('Name')).toBeTruthy()
    expect(screen.queryByText('Active')).toBeNull()
    expect(screen.queryByText('No')).toBeNull()
  })

  it('renders every field of a record that hides none', () => {
    renderDrawer({ id: 1, name: 'Ann', active: false })

    expect(screen.getByText('Active')).toBeTruthy()
    expect(screen.getByText('No')).toBeTruthy()
  })
})
