import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import type { FieldDefinition, OverrideProps, ResourceRecord, ResourceSchema, SectionDefinition } from '@/types'
import type { FieldInputProps } from '@/components/fields/types'

/*
 * The create and update drawers wrote `grid-column: span {colSpan}` inline
 * on every loose field, ignoring `colSpanMd()` / `colSpanLg()`, inside a form
 * that is a flex stack (so the declaration did nothing). Fields are placed by
 * span only inside the Section, Panel and Tab grids, which carry the tiers as
 * custom properties for `.martis-field-grid`; nothing in the drawer form
 * writes an inline `grid-column` any more.
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: vi.fn(() => new Promise(() => {})),
      post: vi.fn(() => new Promise(() => {})),
      put: vi.fn(() => new Promise(() => {})),
    },
  }
})

// DrawerShell renders its children into the DOM so assertions work.
vi.mock('./DrawerShell', () => ({
  DrawerShell: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}))

import { DrawerCreate } from './DrawerCreate'
import { DrawerUpdate } from './DrawerUpdate'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'
import { componentRegistry } from '@/lib/componentRegistry'

registerDefaultFields()

function SpanProbe({ field }: FieldInputProps) {
  return <span data-testid={`probe-${field.attribute}`} />
}
componentRegistry.registerFieldInput('span_probe', SpanProbe)

function field(attribute: string, spans: Record<string, number | null>): FieldDefinition {
  return {
    attribute, label: attribute, type: 'span_probe', nullable: true, readonly: false, required: false,
    sortable: false, searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], colSpan: 12, colSpanMd: null, colSpanLg: null, ...spans,
  } as unknown as FieldDefinition
}

const layout = [
  field('nickname', { colSpan: 6 }),
  {
    type: 'section', title: 'Contact', columns: 12, collapsible: false, collapsedByDefault: false, limit: null,
    fields: [field('email', { colSpan: 12, colSpanMd: 6, colSpanLg: 4 })],
  } satisfies SectionDefinition,
] as unknown as FieldDefinition[]

function props(schema: Record<string, unknown>, extra: Partial<OverrideProps> = {}): OverrideProps {
  return {
    schema: {
      uriKey: 'people', label: 'People', singularLabel: 'Person', fields: [],
      errorDisplay: 'inline', confirmUnsavedChanges: false, ...schema,
    } as unknown as ResourceSchema,
    resource: 'people',
    params: {},
    navigate: vi.fn(),
    onClose: vi.fn(),
    onCreated: vi.fn(),
    onUpdated: vi.fn(),
    onDeleted: vi.fn(),
    onEdit: vi.fn(),
    onView: vi.fn(),
    addToast: vi.fn(),
    ...extra,
  }
}

function renderWithClient(ui: React.ReactElement) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

function expectPlacedByTiers(form: HTMLFormElement): void {
  // The section field carries its cascade for .martis-field-grid ...
  const email = screen.getByTestId('probe-email').closest('.martis-field-grid > *') as HTMLElement
  expect(email).not.toBeNull()
  expect(email.style.getPropertyValue('--martis-field-span-md')).toBe('6')
  expect(email.style.getPropertyValue('--martis-field-span-lg')).toBe('4')
  // ... and nothing in the form is placed by an inline grid-column.
  expect(form.querySelectorAll('[style*="grid-column"]')).toHaveLength(0)
  expect(screen.getByTestId('probe-nickname')).toBeTruthy()
}

describe('Drawer forms place fields by their responsive spans (v1.38.0+)', () => {
  it('create drawer', () => {
    renderWithClient(<DrawerCreate {...props({ fieldsForCreate: layout })} />)

    expectPlacedByTiers(document.getElementById('martis-drawer-create-form') as HTMLFormElement)
  })

  it('update drawer', async () => {
    renderWithClient(
      <DrawerUpdate
        {...props(
          { fieldsForUpdate: layout },
          { record: { id: 7, nickname: 'Ada', email: 'ada@example.com' } as unknown as ResourceRecord, recordId: '7' },
        )}
      />,
    )

    await screen.findByTestId('probe-email')
    expectPlacedByTiers(document.getElementById('martis-drawer-update-form') as HTMLFormElement)
  })
})
