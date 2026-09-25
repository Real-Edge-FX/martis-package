import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import type { FieldDefinition } from '@/types'
import type { ActionMeta } from '@/components/Actions'

/*
 * As in Nova, a relationship panel offers the related resource's row actions
 * (`showInline()`) on each listed record, the way the resource index does:
 * the same menu, disabled per record by its `_actionAuthorization`, and run
 * through the same ActionModal on that one record. BelongsToMany and
 * MorphToMany keep their pivot actions instead.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

import { FieldDisplay, registerDefaultFields } from './FieldRenderer'
import { NestedParentProvider } from './NestedParentContext'
import { ToastProvider } from '@/contexts/ToastContext'

registerDefaultFields()

function action(overrides: Partial<ActionMeta>): ActionMeta {
  return {
    uriKey: 'close-task', name: 'Close task', icon: null, showIcon: true, iconColor: null, group: null,
    destructive: false, showOnIndex: false, showOnDetail: true, showInline: true, executionMode: 'modal',
    standalone: false, sole: false, queued: false, withConfirmation: true, confirmText: 'Close it?',
    confirmButtonText: null, cancelButtonText: null, modalSize: 'md', supportsDryRun: false,
    customComponent: null, customComponentProps: {}, logEvents: false, isPivotAction: false, pivotLabel: null,
    ...overrides,
  }
}

const titleField = {
  attribute: 'title', label: 'Title', type: 'text',
  nullable: false, readonly: false, required: true, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [],
}

function answerWith(actions: ActionMeta[], rows: Array<Record<string, unknown>>) {
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/tasks/schema') {
      return Promise.resolve({ data: { fieldsForIndex: [titleField], fieldsForDetail: [titleField], singularLabel: 'Task', softDeletes: false, actions } })
    }
    if (path.includes('/actions')) return Promise.resolve({ data: { actions: [] } })
    return Promise.resolve({
      data: rows,
      meta: { current_page: 1, from: 1, last_page: 1, per_page: 10, to: rows.length, total: rows.length },
      links: { first: null, last: null, prev: null, next: null },
    })
  })
}

function panel(type: string, metaKey: string): FieldDefinition {
  return {
    attribute: 'tasks', label: 'Tasks', type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: false, rules: [],
    relationship: 'tasks', relatedResource: 'tasks',
    [metaKey]: { perPage: 10, perPageOptions: [10], searchable: false, canCreate: false, canUpdate: true, canDelete: true, canAttach: true, canDetach: true },
  } as unknown as FieldDefinition
}

function renderPanel(field: FieldDefinition) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <MemoryRouter>
          <NestedParentProvider value={{ resource: 'projects', id: 3 }}>
            <FieldDisplay field={field} value={null} resourceKey="projects" context="detail" />
          </NestedParentProvider>
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

function rowMenu(text: string): HTMLElement | null {
  return screen.getByText(text).closest('tr')?.querySelector('button[aria-label="Actions"]') ?? null
}

const ROWS = [
  { id: 1, title: 'Open task', _title: 'Open task', _actionAuthorization: { 'close-task': true } },
  { id: 2, title: 'Locked task', _title: 'Locked task', _actionAuthorization: { 'close-task': false } },
]

beforeEach(() => {
  apiGetMock.mockReset()
})

describe.each([
  ['has_many', 'hasManyMeta'],
  ['has_many_through', 'hasManyMeta'],
  ['morph_many', 'morphManyMeta'],
])('%s panel: row actions', (type, metaKey) => {
  it('offers the inline actions on each row, disabled where the record may not run them', async () => {
    answerWith([action({})], ROWS)
    renderPanel(panel(type, metaKey))
    await screen.findByText('Locked task')

    fireEvent.click(rowMenu('Open task')!)
    expect((screen.getByRole('button', { name: 'Close task' }) as HTMLButtonElement).disabled).toBe(false)
    fireEvent.mouseDown(document.body)

    fireEvent.click(rowMenu('Locked task')!)
    expect((screen.getByRole('button', { name: 'Close task' }) as HTMLButtonElement).disabled).toBe(true)
  })

  it('runs the action on that record through the action modal', async () => {
    answerWith([action({})], ROWS)
    renderPanel(panel(type, metaKey))
    await screen.findByText('Open task')

    fireEvent.click(rowMenu('Open task')!)
    fireEvent.click(screen.getByRole('button', { name: 'Close task' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('Close it?')).toBeTruthy()
  })

  it('shows no menu when the related resource has no inline action', async () => {
    answerWith([action({ showInline: false, showOnIndex: true })], ROWS)
    renderPanel(panel(type, metaKey))
    await screen.findByText('Open task')

    expect(rowMenu('Open task')).toBeNull()
  })
})

describe('belongs_to_many panel', () => {
  it('keeps its pivot actions and shows no resource row menu', async () => {
    answerWith([action({})], ROWS.map((r) => ({ ...r, _pivot: {} })))
    renderPanel(panel('belongs_to_many', 'belongsToManyMeta'))
    await screen.findByText('Open task')

    expect(rowMenu('Open task')).toBeNull()
  })
})
