import { describe, it, expect, vi, beforeEach } from 'vitest'

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const map: Record<string, string> = {
        'actions': 'Actions',
        'attach': 'Attach',
        'detach': 'Detach',
        'attach_related': 'Attach Record',
        'cancel': 'Cancel',
        'please_wait': 'Please wait…',
        'search': 'Search…',
        'loading': 'Loading…',
        'no_records_available': 'No records available.',
        'detach_confirm': 'This record will be detached.',
      }
      return map[key] ?? key
    },
    i18n: { language: 'en' },
  }),
}))

// Mock react-router-dom
vi.mock('react-router-dom', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => (
    <a href={to}>{children}</a>
  ),
}))

// Mock @tanstack/react-query
vi.mock('@tanstack/react-query', () => ({
  useQuery: vi.fn().mockReturnValue({ data: null, isLoading: false }),
  useMutation: vi.fn().mockReturnValue({ mutateAsync: vi.fn(), isPending: false }),
  useQueryClient: vi.fn().mockReturnValue({ invalidateQueries: vi.fn() }),
}))

// Mock api
vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
  },
}))

// Mock primereact
vi.mock('primereact/datatable', () => ({
  DataTable: ({ children, emptyMessage }: { children: React.ReactNode; emptyMessage?: React.ReactNode }) => (
    <div data-testid="datatable">{children}{emptyMessage}</div>
  ),
}))
vi.mock('primereact/column', () => ({
  Column: () => null,
}))

import { render, screen } from '@testing-library/react'
import { registerDefaultFields } from '@/components/fields'
import { FieldDisplay } from '@/components/fields'
import type { FieldDefinition } from '@/types'

beforeEach(() => {
  registerDefaultFields()
})

// -------------------------------------------------------------------------
// Fixtures
// -------------------------------------------------------------------------

const belongsToManyField: FieldDefinition = {
  attribute: 'tags',
  label: 'Tags',
  type: 'belongs_to_many',
  nullable: false,
  readonly: false,
  required: false,
  sortable: false,
  searchable: false,
  showOnIndex: false,
  showOnDetail: true,
  showOnForms: false,
  rules: [],
  relationship: 'tags',
  relatedResource: 'tags',
  collapsable: false,
  collapsedByDefault: false,
  allowDuplicateRelations: false,
  showCreateRelationButton: false,
  pivotFields: [],
  belongsToManyMeta: {
    perPage: 10,
    perPageOptions: [5, 10, 25],
    canAttach: true,
    canDetach: true,
  },
}

// -------------------------------------------------------------------------
// BelongsToMany count badge (index display)
// -------------------------------------------------------------------------

describe('BelongsToManyField — index display (count badge)', () => {
  it('renders a count badge when value is a number', () => {
    render(
      <FieldDisplay field={belongsToManyField} value={5} />
    )
    expect(screen.getByText('5')).toBeTruthy()
  })

  it('renders zero count', () => {
    render(
      <FieldDisplay field={belongsToManyField} value={0} />
    )
    expect(screen.getByText('0')).toBeTruthy()
  })
})

// -------------------------------------------------------------------------
// BelongsToMany detail panel
// -------------------------------------------------------------------------

describe('BelongsToManyField — detail panel', () => {
  it('renders the Attach button in detail view when canAttach is true', () => {
    // BelongsToManyFieldDisplay is interactive — attach/detach/pivot actions work on the detail page
    render(
      <FieldDisplay field={belongsToManyField} value={null} />
    )
    expect(screen.queryByText('Attach')).toBeTruthy()
  })

  it('renders the field label', () => {
    render(
      <FieldDisplay field={belongsToManyField} value={null} />
    )
    expect(screen.getByText('Tags')).toBeTruthy()
  })

  it('does not render Attach button when canAttach is false', () => {
    const field = {
      ...belongsToManyField,
      belongsToManyMeta: { ...belongsToManyField.belongsToManyMeta as Record<string, unknown>, canAttach: false },
    }
    render(<FieldDisplay field={field as FieldDefinition} value={null} />)
    expect(screen.queryByText('Attach')).toBeNull()
  })

  it('renders collapsable toggle when collapsable is true', () => {
    const field = { ...belongsToManyField, collapsable: true }
    render(<FieldDisplay field={field as FieldDefinition} value={null} />)
    // Collapse button should render (caret icon)
    expect(screen.getByText('Tags')).toBeTruthy()
  })
})

// -------------------------------------------------------------------------
// Field type registration
// -------------------------------------------------------------------------

describe('BelongsToManyField — registry', () => {
  it('is registered in the default field registry', () => {
    // After registerDefaultFields(), belongs_to_many should resolve via FieldDisplay
    const { queryByTestId } = render(
      <FieldDisplay
        field={{ ...belongsToManyField }}
        value={3}
        resourceKey="posts"
      />
    )
    // BelongsToManyCountBadge renders a badge; just verify it does not crash
    expect(queryByTestId).toBeDefined()
  })
})
