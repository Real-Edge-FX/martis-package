import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ApiError } from '@/lib/api'

// Relationship panels shared the resource index's blind spot: a failing
// records fetch fed the DataTable `[]` and "No records available." rendered
// under PrimeReact's loading overlay, so a 500 on a has-many / belongs-to-
// many panel read as "this record has no children". The shell now renders
// the compact inline error state (with Retry) instead of the table, and
// withholds the empty copy until the result is authoritative.

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: string | { defaultValue?: string }) =>
      typeof opts === 'string' ? opts : opts?.defaultValue ?? key,
    i18n: { language: 'en' },
  }),
}))

vi.mock('react-router-dom', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => <a href={to}>{children}</a>,
  useNavigate: () => vi.fn(),
}))

// Mutable records-query state so each test picks pending / error / empty.
type RecordsState = {
  data?: { data: unknown[]; meta: { total: number } }
  error?: unknown
  isLoading: boolean
  isFetching: boolean
  isError: boolean
  isSuccess: boolean
  refetch: ReturnType<typeof vi.fn>
}
const recordsState: { current: RecordsState } = { current: pending() }

function pending(): RecordsState {
  return { data: undefined, isLoading: true, isFetching: true, isError: false, isSuccess: false, refetch: vi.fn() }
}
function failed(error: unknown): RecordsState {
  return { data: undefined, error, isLoading: false, isFetching: false, isError: true, isSuccess: false, refetch: vi.fn() }
}
function empty(): RecordsState {
  return { data: { data: [], meta: { total: 0 } }, isLoading: false, isFetching: false, isError: false, isSuccess: true, refetch: vi.fn() }
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: ({ queryKey }: { queryKey: unknown[] }) => {
      if (queryKey[0] === 'schema') {
        return { data: { data: { fieldsForIndex: [{ attribute: 'name', label: 'Name' }], softDeletes: false } }, isLoading: false }
      }
      return recordsState.current
    },
    useMutation: () => ({ mutate: vi.fn(), isPending: false }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

// Render the DataTable's empty message so the gating is assertable; the real
// DataTable is heavy in jsdom and would obscure the assertion.
vi.mock('primereact/datatable', () => ({
  DataTable: ({ value, emptyMessage }: { value: unknown[]; emptyMessage?: React.ReactNode }) => (
    <table data-testid="datatable"><tbody>{value.length === 0 && <tr><td>{emptyMessage}</td></tr>}</tbody></table>
  ),
}))
vi.mock('primereact/column', () => ({ Column: () => null }))

vi.mock('@/components/fields/FieldRenderer', () => ({ FieldDisplay: () => null }))
vi.mock('@/components/DeleteModal', () => ({ DeleteModal: () => null }))
vi.mock('@/components/ResourceIcon', () => ({ ResourceIcon: () => null }))
vi.mock('@/components/Pagination', () => ({ Pagination: () => null }))

import { RelationshipTableShell } from './RelationshipTableShell'

function renderShell() {
  return render(<RelationshipTableShell
    title="Comments"
    relatedResource="comments"
    queryKey={['rel', 'comments']}
    fetchUrl={() => '/api/resources/posts/1/has-many/comments'}
    perPage={10}
    perPageOptions={[10, 25]}
    searchable={false}
    canCreate={false}
    canUpdate={false}
    canDelete={false}
  />)
}

function errorState(): HTMLElement | null {
  return document.querySelector<HTMLElement>('.martis-query-error')
}

beforeEach(() => {
  recordsState.current = pending()
})

describe('RelationshipTableShell — records fetch failure', () => {
  it('renders the compact inline error state instead of the table when the fetch fails', () => {
    recordsState.current = failed(new ApiError(500, 'Server Error'))

    renderShell()

    const state = errorState()
    expect(state).not.toBeNull()
    expect(state?.classList.contains('martis-query-error-compact')).toBe(true)
    expect(state?.textContent).toContain('Records could not be loaded')
    expect(state?.textContent).toContain('HTTP 500')
    expect(screen.queryByTestId('datatable')).toBeNull()
    expect(screen.queryByText('No records available.')).toBeNull()
  })

  it('wires the Retry button to the records query refetch', () => {
    const state = failed(new ApiError(503, 'Service Unavailable'))
    recordsState.current = state

    renderShell()

    screen.getByRole('button', { name: /try again/i }).click()
    expect(state.refetch).toHaveBeenCalledTimes(1)
  })

  it('withholds "No records available." while the first fetch is pending', () => {
    recordsState.current = pending()

    renderShell()

    expect(screen.getByTestId('datatable')).not.toBeNull()
    expect(screen.queryByText('No records available.')).toBeNull()
    expect(errorState()).toBeNull()
  })

  it('still renders "No records available." for a successful empty result (regression)', () => {
    recordsState.current = empty()

    renderShell()

    expect(screen.getByText('No records available.')).not.toBeNull()
    expect(errorState()).toBeNull()
  })
})
