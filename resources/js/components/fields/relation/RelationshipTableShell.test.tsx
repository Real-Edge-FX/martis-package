import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'

// RP-139: the trailing "Actions" column must collapse when a row would render
// zero actions. The shell keys the column on `hasActions` (which includes
// `!!rowActionsExtras`), so a read-only panel that passes no rowActionsExtras
// and hides every built-in action must render NO "Actions" header.

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => fallback ?? key,
    i18n: { language: 'en' },
  }),
}))

vi.mock('react-router-dom', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => <a href={to}>{children}</a>,
  useNavigate: () => vi.fn(),
}))

// Distinguish the two useQuery calls by their queryKey: the schema fetch keys
// on ['schema', …]; everything else is the records fetch.
vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: ({ queryKey }: { queryKey: unknown[] }) => {
      if (queryKey[0] === 'schema') {
        return { data: { data: { fieldsForIndex: [{ attribute: 'name', label: 'Name' }], softDeletes: false } }, isLoading: false }
      }
      return { data: { data: [{ id: 1, name: 'Row one', _title: 'Row one' }], meta: { total: 1 } }, isLoading: false }
    },
    useMutation: () => ({ mutate: vi.fn(), isPending: false }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

vi.mock('@/lib/api', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

// Render each Column's `header` so the "Actions" header is assertable; the
// real DataTable/Column are heavy in jsdom and would obscure the assertion.
vi.mock('primereact/datatable', () => ({
  DataTable: ({ children }: { children: React.ReactNode }) => <table><thead><tr>{children}</tr></thead></table>,
}))
vi.mock('primereact/column', () => ({
  Column: ({ header }: { header?: React.ReactNode }) => <th>{header}</th>,
}))

// Trim heavy leaves that are irrelevant to the Actions-column assertion.
vi.mock('@/components/fields/FieldRenderer', () => ({ FieldDisplay: () => null }))
vi.mock('@/components/DeleteModal', () => ({ DeleteModal: () => null }))
vi.mock('@/components/ResourceIcon', () => ({ ResourceIcon: () => null }))
vi.mock('@/components/Pagination', () => ({ Pagination: () => null }))

import { RelationshipTableShell } from './RelationshipTableShell'

function baseProps(overrides: Record<string, unknown> = {}) {
  return {
    title: 'Recent usage',
    relatedResource: 'events',
    queryKey: ['rel', 'events'],
    fetchUrl: () => '/api/resources/events',
    perPage: 10,
    perPageOptions: [10, 25],
    searchable: false,
    canCreate: false,
    canUpdate: false,
    canDelete: false,
    ...overrides,
  }
}

describe('RelationshipTableShell — Actions column collapse (RP-139)', () => {
  it('renders NO "Actions" header for a fully read-only panel (all actions hidden, no rowActionsExtras)', () => {
    render(<RelationshipTableShell
      {...baseProps({
        hideViewAction: true,
        hideEditAction: true,
        hideDeleteAction: true,
        // no rowActionsExtras, no edit/view/delete URLs, non-soft-delete resource
      })}
    />)
    expect(screen.queryByText('Actions')).toBeNull()
  })

  it('renders the "Actions" header when a built-in action survives (view not hidden)', () => {
    render(<RelationshipTableShell {...baseProps({ hideEditAction: true, hideDeleteAction: true })} />)
    // showView = !hideViewAction defaults to true → the column must render.
    expect(screen.queryByText('Actions')).not.toBeNull()
  })

  it('renders the "Actions" header when rowActionsExtras is provided (e.g. detach)', () => {
    render(<RelationshipTableShell
      {...baseProps({
        hideViewAction: true,
        hideEditAction: true,
        hideDeleteAction: true,
        rowActionsExtras: () => <button type="button">Detach</button>,
      })}
    />)
    expect(screen.queryByText('Actions')).not.toBeNull()
  })
})
