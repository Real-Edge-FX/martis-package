import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition, OverrideProps, ResourceRecord, ResourceSchema } from '@/types'

/*
 * The detail drawer rows honour `fullWidth()` and `hideLabelOnDetail()` and
 * carry `data-field` / `data-type` hooks, as the detail page rows do.
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

function renderDrawer(fields: FieldDefinition[]) {
  const schema = {
    uriKey: 'employees', label: 'Employees', singularLabel: 'Employee', fields: [],
    fieldsForDetail: fields,
  } as unknown as ResourceSchema
  const props: OverrideProps = {
    schema,
    resource: 'employees',
    params: {},
    record: { id: 1, _title: 'Ann', name: 'Ann', bio: 'Long text', note: 'Hidden label' } as unknown as ResourceRecord,
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
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <DrawerDetail {...props} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('DrawerDetail rows', () => {
  it('keeps the default row and tags it with data-field and data-type', () => {
    const { container } = renderDrawer([field('name', 'Name')])
    const row = container.querySelector('.martis-detail-row')!
    expect(row.getAttribute('data-field')).toBe('name')
    expect(row.getAttribute('data-type')).toBe('text')
    expect(row.classList.contains('is-full-width')).toBe(false)
  })

  it('lays a fullWidth field out as one column', () => {
    const { container } = renderDrawer([{ ...field('bio', 'Bio'), fullWidth: true } as FieldDefinition])
    expect(container.querySelector('.martis-detail-row')!.classList.contains('is-full-width')).toBe(true)
    expect(screen.getByText('Bio')).toBeTruthy()
  })

  it('hides the visible label of a hideLabelOnDetail field but keeps it for screen readers', () => {
    const { container } = renderDrawer([{ ...field('note', 'Note'), hideLabelOnDetail: true } as FieldDefinition])
    const row = container.querySelector('.martis-detail-row')!
    expect(row.classList.contains('is-full-width')).toBe(true)
    expect(row.classList.contains('is-label-hidden')).toBe(true)
    expect(row.querySelector('dt')!.classList.contains('sr-only')).toBe(true)
  })
})
