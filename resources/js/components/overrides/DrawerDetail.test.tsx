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

// Stub out field/panel/section renderers — not under test here.
vi.mock('@/components/fields/FieldRenderer', () => ({
  FieldDisplay: () => null,
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
