import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import type { OverrideProps, ResourceRecord, ResourceSchema } from '@/types'

// ---------------------------------------------------------------------------
// Mocks
// ---------------------------------------------------------------------------

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: vi.fn().mockReturnValue({ data: null, isLoading: false }),
    useMutation: vi.fn().mockReturnValue({ mutateAsync: vi.fn(), isPending: false }),
    useQueryClient: vi.fn().mockReturnValue({ invalidateQueries: vi.fn() }),
  }
})

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), delete: vi.fn() },
}))

// DrawerShell renders children + footer into the DOM so assertions work.
vi.mock('./DrawerShell', () => ({
  DrawerShell: ({ children, footer }: { children: React.ReactNode; footer?: React.ReactNode }) => (
    <div>
      <div data-testid="drawer-content">{children}</div>
      <div data-testid="drawer-footer">{footer}</div>
    </div>
  ),
}))

vi.mock('@/components/DeleteModal', () => ({
  DeleteModal: () => null,
}))

vi.mock('@phosphor-icons/react', () => ({
  TrashIcon: () => <span>TrashIcon</span>,
  PencilSimpleIcon: () => <span>PencilSimpleIcon</span>,
}))

// Stub out field/panel/section renderers — not under test here. FieldDisplay
// emits a marker so a test can assert WHERE a field rendered (scalar grid vs
// standalone relationship panel), not merely that it left the scalar grid.
vi.mock('@/components/fields/FieldRenderer', () => ({
  FieldDisplay: ({ field }: { field: { attribute?: string } }) => (
    <div data-testid={`field-${field?.attribute ?? 'unknown'}`} />
  ),
}))
vi.mock('@/components/fields/FieldLabelTooltip', () => ({
  FieldLabelTooltip: () => null,
}))
vi.mock('@/components/fields/PanelRenderer', () => ({
  PanelDisplay: () => null,
}))
vi.mock('@/components/fields/TabsRenderer', () => ({
  TabsDisplay: () => null,
}))
vi.mock('@/components/fields/SectionRenderer', () => ({
  SectionDisplay: () => null,
}))

import { DrawerDetail } from './DrawerDetail'

// ---------------------------------------------------------------------------
// Fixture helpers
// ---------------------------------------------------------------------------

function makeRecord(auth: Partial<ResourceRecord['_authorization']> = {}): ResourceRecord {
  return {
    id: 1,
    _title: 'Test Record',
    _resource: { uriKey: 'posts', label: 'Post', singularLabel: 'Post' } as ResourceRecord['_resource'],
    _authorization: {
      authorizedToView: true,
      authorizedToUpdate: true,
      authorizedToDelete: true,
      authorizedToReplicate: true,
      authorizedToRunAction: true,
      authorizedToRunDestructiveAction: true,
      ...auth,
    },
  }
}

const baseSchema = {
  label: 'Posts',
  singularLabel: 'Post',
  uriKey: 'posts',
  fieldsForDetail: [],
  softDeletes: false,
} as unknown as ResourceSchema

const baseProps: OverrideProps = {
  schema: baseSchema,
  resource: 'posts',
  params: {},
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

// ---------------------------------------------------------------------------
// DrawerDetail — per-record authorization UI guard
// ---------------------------------------------------------------------------

describe('DrawerDetail footer authorization guards', () => {
  it('shows both buttons when the record authorizes update and delete', () => {
    render(<DrawerDetail {...baseProps} record={makeRecord()} />)
    const footer = screen.getByTestId('drawer-footer')
    expect(footer.textContent).toContain('delete')
    expect(footer.textContent).toContain('edit')
  })

  it('hides the Delete button when authorizedToDelete is false', () => {
    render(<DrawerDetail {...baseProps} record={makeRecord({ authorizedToDelete: false })} />)
    const footer = screen.getByTestId('drawer-footer')
    expect(footer.textContent).not.toContain('delete')
    expect(footer.textContent).toContain('edit')
  })

  it('hides the Edit button when authorizedToUpdate is false', () => {
    render(<DrawerDetail {...baseProps} record={makeRecord({ authorizedToUpdate: false })} />)
    const footer = screen.getByTestId('drawer-footer')
    expect(footer.textContent).toContain('delete')
    expect(footer.textContent).not.toContain('edit')
  })

  it('hides both buttons when neither delete nor update is authorized', () => {
    render(
      <DrawerDetail
        {...baseProps}
        record={makeRecord({ authorizedToUpdate: false, authorizedToDelete: false })}
      />,
    )
    const footer = screen.getByTestId('drawer-footer')
    expect(footer.textContent).not.toContain('delete')
    expect(footer.textContent).not.toContain('edit')
  })

  it('hides both buttons while the record is still loading (activeRecord is undefined)', () => {
    // record prop is absent and useQuery mock returns no data — activeRecord is undefined.
    render(<DrawerDetail {...baseProps} record={undefined} />)
    const footer = screen.getByTestId('drawer-footer')
    expect(footer.textContent).not.toContain('delete')
    expect(footer.textContent).not.toContain('edit')
  })
})

// ---------------------------------------------------------------------------
// DrawerDetail — relationship partitioning
//
// Regression: belongs_to_many / morph_to_many were missing from the drawer's
// STANDALONE_RELATIONSHIP_TYPES set (a drift from ResourceDetail's copy), so
// they fell into the scalar dl/dt/dd grid and rendered with a ~140px label
// gutter that squeezed the panel — plus a duplicate heading.
// ---------------------------------------------------------------------------

function schemaWithFields(fields: Array<{ attribute: string; label: string; type: string }>): ResourceSchema {
  return { ...baseSchema, fieldsForDetail: fields } as unknown as ResourceSchema
}

describe('DrawerDetail relationship partitioning', () => {
  it('renders belongs_to_many as a standalone panel, not a squeezed scalar row', () => {
    const schema = schemaWithFields([
      { attribute: 'name', label: 'Name', type: 'text' },
      { attribute: 'tags', label: 'Tags', type: 'belongs_to_many' },
    ])
    render(<DrawerDetail {...baseProps} schema={schema} record={makeRecord()} />)

    const content = screen.getByTestId('drawer-content')
    const labelTexts = Array.from(content.querySelectorAll('.martis-detail-label')).map((el) => el.textContent)

    // The scalar detail grid carries 'Name' but NOT the belongs_to_many
    // 'Tags' — a scalar row would give it a <dt class="martis-detail-label">.
    expect(labelTexts).toContain('Name')
    expect(labelTexts).not.toContain('Tags')
    // Positive assertion: 'Tags' still rendered — as a standalone panel, not
    // dropped. (A refactor that filtered it out of BOTH partitions would fail
    // here even though the negative label assertion above would still pass.)
    expect(screen.queryByTestId('field-tags')).not.toBeNull()
  })

  it('treats morph_to_many as a standalone panel too (no scalar label gutter)', () => {
    const schema = schemaWithFields([
      { attribute: 'roles', label: 'Roles', type: 'morph_to_many' },
    ])
    render(<DrawerDetail {...baseProps} schema={schema} record={makeRecord()} />)

    const content = screen.getByTestId('drawer-content')
    // Only relationship fields present → the scalar grid must not render at all…
    expect(content.querySelectorAll('.martis-detail-label').length).toBe(0)
    // …and the field still rendered as a standalone panel (not dropped).
    expect(screen.queryByTestId('field-roles')).not.toBeNull()
  })
})
