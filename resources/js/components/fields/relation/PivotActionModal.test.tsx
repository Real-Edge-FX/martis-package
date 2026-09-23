import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactElement, ReactNode } from 'react'

// A BelongsToMany or MorphToMany panel lists its pivot actions, and the pivot
// action modal reads the chosen action's fields and runs it, all under the
// panel's own pivot actions endpoint. The fields used to come from the
// resource action endpoint (`/api/resources/{resource}/actions/{uriKey}/fields`),
// which does not know an action declared on the field with ->actions().

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => fallback ?? key,
    i18n: { language: 'en' },
  }),
}))

// The detail page the panel renders on. Both panels read their parent record
// from the route; each test names the page it runs on.
const page = { params: { resource: 'users', id: '7' } }

vi.mock('react-router-dom', () => ({
  useParams: () => page.params,
  Link: ({ children, to }: { children: ReactNode; to: string }) => <a href={to}>{children}</a>,
}))

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  ApiError: class ApiError extends Error {},
}))

vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))
vi.mock('@/lib/historyLock', () => ({ useModalHistoryLock: () => undefined }))
vi.mock('@/components/fields/FieldRenderer', () => ({
  FieldDisplay: () => null,
  FieldInput: () => null,
}))

// The shell owns the records table; here it only exposes the selection and the
// toolbar the panel plugs its pivot actions into.
vi.mock('@/components/fields/relation/RelationshipTableShell', () => ({
  RelationshipTableShell: ({ onSelectionChange, toolbarExtras }: {
    onSelectionChange?: (rows: Array<Record<string, unknown>>) => void
    toolbarExtras?: (ctx: { selectedRows: Array<Record<string, unknown>> }) => ReactNode
  }) => {
    const rows = [{ id: 3 }, { id: 5 }]
    return (
      <div>
        <button type="button" onClick={() => onSelectionChange?.(rows)}>select rows</button>
        {toolbarExtras?.({ selectedRows: rows })}
      </div>
    )
  },
}))

import { api } from '@/lib/api'
import { BelongsToManyFieldDisplay } from '@/components/fields/BelongsToManyField'
import { MorphToManyFieldDisplay } from '@/components/fields/MorphToManyField'
import type { FieldDefinition } from '@/types'

const action = {
  uriKey: 'report-priorities',
  name: 'Report priorities',
  standalone: false,
  destructive: false,
  withConfirmation: true,
  confirmText: null,
  confirmButtonText: 'Run it',
  cancelButtonText: null,
  modalSize: 'md',
  isPivotAction: true,
  pivotLabel: null,
}

function mockPivotEndpoints(actionsUrl: string) {
  vi.mocked(api.get).mockImplementation((url: string) => {
    if (url === `${actionsUrl}?context=detail`) return Promise.resolve({ data: { actions: [action] } })
    if (url === `${actionsUrl}/report-priorities/fields`) return Promise.resolve({ data: { fields: [] } })
    return Promise.reject(new Error(`Unexpected GET ${url}`))
  })
  vi.mocked(api.post).mockResolvedValue({ data: { type: 'message', data: { message: 'Done' } } })
}

async function runPivotActionFrom(panel: ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(<QueryClientProvider client={client}>{panel}</QueryClientProvider>)

  fireEvent.click(screen.getByRole('button', { name: 'select rows' }))
  fireEvent.click(await screen.findByRole('button', { name: /Actions/ }))
  fireEvent.click(screen.getByRole('button', { name: 'Report priorities' }))
  fireEvent.click(await screen.findByRole('button', { name: /Run it/ }))
}

beforeEach(() => {
  page.params = { resource: 'users', id: '7' }
  vi.mocked(api.get).mockReset()
  vi.mocked(api.post).mockReset()
})

describe('pivot actions on the many-to-many panels', () => {
  it('reads the fields of a MorphToMany pivot action and runs it under the morph-to-many relationship', async () => {
    page.params = { resource: 'projects', id: '7' }
    const actionsUrl = '/api/resources/projects/7/morph-to-many/tags/actions'
    mockPivotEndpoints(actionsUrl)

    const field = { attribute: 'tags', label: 'Tags', type: 'morph_to_many', relationship: 'tags', relatedResource: 'tags' }
    await runPivotActionFrom(<MorphToManyFieldDisplay field={field as unknown as FieldDefinition} value={undefined} />)

    await waitFor(() => {
      expect(api.post).toHaveBeenCalledWith(`${actionsUrl}/report-priorities`, { resources: [3, 5], fields: {} })
    })
    expect(api.get).toHaveBeenCalledWith(`${actionsUrl}/report-priorities/fields`, expect.anything())
  })

  it('reads the fields of a BelongsToMany pivot action and runs it under the belongs-to-many relationship', async () => {
    const actionsUrl = '/api/resources/users/7/belongs-to-many/roles/actions'
    mockPivotEndpoints(actionsUrl)

    const field = { attribute: 'roles', label: 'Roles', type: 'belongs_to_many', relationship: 'roles', relatedResource: 'roles' }
    await runPivotActionFrom(<BelongsToManyFieldDisplay field={field as unknown as FieldDefinition} value={undefined} />)

    await waitFor(() => {
      expect(api.post).toHaveBeenCalledWith(`${actionsUrl}/report-priorities`, { resources: [3, 5], fields: {} })
    })
    expect(api.get).toHaveBeenCalledWith(`${actionsUrl}/report-priorities/fields`, expect.anything())
  })

  it('opens only the dropdown clicked when the pivot actions carry different labels', async () => {
    page.params = { resource: 'projects', id: '7' }
    const actionsUrl = '/api/resources/projects/7/morph-to-many/tags/actions'
    const retag = { ...action, uriKey: 'retag', name: 'Retag', pivotLabel: 'Tag assignment' }
    vi.mocked(api.get).mockImplementation((url: string) => {
      if (url === `${actionsUrl}?context=detail`) return Promise.resolve({ data: { actions: [retag, action] } })
      if (url === `${actionsUrl}/retag/fields`) return Promise.resolve({ data: { fields: [] } })
      return Promise.reject(new Error(`Unexpected GET ${url}`))
    })

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const field = { attribute: 'tags', label: 'Tags', type: 'morph_to_many', relationship: 'tags', relatedResource: 'tags' }
    render(
      <QueryClientProvider client={client}>
        <MorphToManyFieldDisplay field={field as unknown as FieldDefinition} value={undefined} />
      </QueryClientProvider>,
    )

    fireEvent.click(screen.getByRole('button', { name: 'select rows' }))
    fireEvent.click(await screen.findByRole('button', { name: /Tag assignment/ }))

    expect(screen.queryByRole('button', { name: 'Report priorities' })).toBeNull()

    // A real click starts with a mousedown, which must not count as a click
    // outside the dropdown that holds the item.
    const item = screen.getByRole('button', { name: 'Retag' })
    fireEvent.mouseDown(item)
    fireEvent.click(item)

    expect(await screen.findByRole('button', { name: /Run it/ })).toBeTruthy()
  })
})
