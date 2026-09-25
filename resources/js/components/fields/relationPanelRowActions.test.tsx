import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, within, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition } from '@/types'
import type { ActionMeta } from '@/components/Actions'

/*
 * As in Nova, a relationship panel offers the related resource's row actions
 * (`showInline()`) on each listed record, laid out as on the resource index
 * (an ungrouped action is an icon button, grouped ones sit in the "..."
 * menu), disabled per record by its `_actionAuthorization`, and run through
 * the same ActionModal on that one record, through the relationship
 * (`viaResource`, `viaResourceId`, `viaRelationship`, as Nova sends them).
 * BelongsToMany and MorphToMany keep their pivot actions instead.
 */

const apiGetMock = vi.fn()
const apiPostMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
      post: (...args: unknown[]) => apiPostMock(...args),
    },
  }
})

import { FieldDisplay, registerDefaultFields } from './FieldRenderer'
import { NestedParentProvider } from './NestedParentContext'
import { ToastProvider } from '@/contexts/ToastContext'
import { componentRegistry } from '@/lib/componentRegistry'
import { ApiError } from '@/lib/api'

registerDefaultFields()
componentRegistry.register('martis:drawer-detail', (({ recordId }: { recordId: string | null }) => <div>Detail drawer {recordId}</div>) as never)
componentRegistry.register('martis:drawer-create', (({ resource }: { resource: string }) => <div>Create drawer {resource}</div>) as never)
componentRegistry.register('martis:drawer-update', (({ recordId }: { recordId: string | null }) => <div>Update drawer {recordId}</div>) as never)

function action(overrides: Partial<ActionMeta>): ActionMeta {
  return {
    uriKey: 'close-task', name: 'Close task', icon: null, showIcon: true, iconColor: null, group: null,
    destructive: false, showOnIndex: false, showOnDetail: true, showInline: true, executionMode: 'modal',
    standalone: false, sole: false, queued: false, withConfirmation: true, confirmText: 'Close it?',
    confirmButtonText: 'Close now', cancelButtonText: null, modalSize: 'md', supportsDryRun: false,
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

function rowOf(text: string): HTMLElement {
  return screen.getByText(text).closest('tr') as HTMLElement
}

function rowButton(text: string, name: string): HTMLButtonElement | null {
  return within(rowOf(text)).queryByRole('button', { name }) as HTMLButtonElement | null
}

const ROWS = [
  { id: 1, title: 'Open task', _title: 'Open task', _actionAuthorization: { 'close-task': true } },
  { id: 2, title: 'Locked task', _title: 'Locked task', _actionAuthorization: { 'close-task': false } },
  // No map entry: the record's run-action policy decides, as on the index.
  { id: 3, title: 'Denied task', _title: 'Denied task', _authorization: { authorizedToRunAction: false } },
  { id: 4, title: 'Trashed task', _title: 'Trashed task', deleted_at: '2026-09-01 10:00:00', _actionAuthorization: { 'close-task': true } },
]

const VIA = { viaResource: 'projects', viaResourceId: '3', viaRelationship: 'tasks' }

async function runOn(text: string) {
  fireEvent.click(rowButton(text, 'Close task')!)
  const dialog = await screen.findByRole('dialog')
  fireEvent.click(within(dialog).getByRole('button', { name: 'Close now' }))
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiPostMock.mockReset()
})

describe.each([
  ['has_many', 'hasManyMeta'],
  ['has_many_through', 'hasManyMeta'],
  ['morph_many', 'morphManyMeta'],
])('%s panel: row actions', (type, metaKey) => {
  it('offers an ungrouped inline action as a row button, disabled where the record may not run it', async () => {
    answerWith([action({})], ROWS)
    renderPanel(panel(type, metaKey))
    await screen.findByText('Locked task')

    expect(rowButton('Open task', 'Close task')!.disabled).toBe(false)
    expect(rowButton('Locked task', 'Close task')!.disabled).toBe(true)
    expect(rowButton('Denied task', 'Close task')!.disabled).toBe(true)
    expect(rowButton('Trashed task', 'Close task')!.disabled).toBe(false)
    expect(within(rowOf('Open task')).queryByRole('button', { name: 'Actions' })).toBeNull()
  })

  it('runs the action on that record through the relationship, then refreshes the rows', async () => {
    answerWith([action({})], ROWS)
    apiPostMock.mockResolvedValue({ data: { type: 'message', data: { message: 'Closed.' } } })
    renderPanel(panel(type, metaKey))
    await screen.findByText('Open task')
    const rowLoads = apiGetMock.mock.calls.filter(([path]) => String(path).includes('/api/resources/projects/3/')).length

    await runOn('Open task')

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1))
    expect(apiPostMock).toHaveBeenCalledWith('/api/resources/tasks/actions/close-task', expect.objectContaining({ resources: [1], ...VIA }))
    await waitFor(() => expect(apiGetMock.mock.calls.filter(([path]) => String(path).includes('/api/resources/projects/3/')).length).toBeGreaterThan(rowLoads))
    expect(await screen.findByText('Closed.')).toBeTruthy()
  })

  it('runs on a trashed row with its own id', async () => {
    answerWith([action({})], ROWS)
    apiPostMock.mockResolvedValue({ data: { type: 'message', data: { message: 'Closed.' } } })
    renderPanel(panel(type, metaKey))
    await screen.findByText('Trashed task')

    await runOn('Trashed task')

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith('/api/resources/tasks/actions/close-task', expect.objectContaining({ resources: [4] })))
  })

  it('shows the refusal of a row the server does not resolve (outside the scope or the relationship)', async () => {
    answerWith([action({})], ROWS)
    apiPostMock.mockRejectedValue(new ApiError(404, 'One or more selected resources could not be found.', []))
    renderPanel(panel(type, metaKey))
    await screen.findByText('Open task')

    await runOn('Open task')

    expect(await screen.findByText('One or more selected resources could not be found.')).toBeTruthy()
  })

  it('runs a standalone inline action on no record', async () => {
    answerWith([action({ standalone: true })], ROWS)
    apiPostMock.mockResolvedValue({ data: { type: 'message', data: { message: 'Done.' } } })
    renderPanel(panel(type, metaKey))
    await screen.findByText('Open task')

    await runOn('Open task')

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith('/api/resources/tasks/actions/close-task', expect.objectContaining({ resources: [], ...VIA })))
  })

  it.each([
    ['openDetail', { resource: 'tasks', recordId: 1 }, 'Detail drawer 1'],
    ['openCreate', { resource: 'tasks' }, 'Create drawer tasks'],
    ['openUpdate', { resource: 'tasks', recordId: 1 }, 'Update drawer 1'],
  ])('opens the drawer an action answers with %s', async (answer, data, drawer) => {
    answerWith([action({})], ROWS)
    apiPostMock.mockResolvedValue({ data: { type: answer, data } })
    renderPanel(panel(type, metaKey))
    await screen.findByText('Open task')

    await runOn('Open task')

    expect(await screen.findByText(drawer)).toBeTruthy()
  })

  it('puts a grouped action in the row menu, which announces itself and takes the focus', async () => {
    answerWith([action({ group: 'Workflow' })], ROWS)
    renderPanel(panel(type, metaKey))
    await screen.findByText('Open task')

    const menuButton = within(rowOf('Open task')).getByRole('button', { name: 'Actions' })
    expect(menuButton.getAttribute('aria-haspopup')).toBe('menu')
    expect(menuButton.getAttribute('aria-expanded')).toBe('false')

    fireEvent.click(menuButton)

    expect(menuButton.getAttribute('aria-expanded')).toBe('true')
    const menu = screen.getByRole('menu')
    expect(menu.contains(document.activeElement)).toBe(true)
  })

  it('moves through the row menu with the arrows, and Escape gives the focus back to its button', async () => {
    answerWith([
      action({ uriKey: 'close-task', name: 'Close task', group: 'Workflow' }),
      action({ uriKey: 'reopen-task', name: 'Reopen task', group: 'Review' }),
    ], ROWS)
    renderPanel(panel(type, metaKey))
    await screen.findByText('Open task')
    const menuButton = within(rowOf('Open task')).getByRole('button', { name: 'Actions' })

    fireEvent.click(menuButton)
    const menu = screen.getByRole('menu')
    const [first, second] = within(menu).getAllByRole('menuitem')
    expect(document.activeElement).toBe(first)

    fireEvent.keyDown(first, { key: 'ArrowDown' })
    expect(document.activeElement).toBe(second)
    fireEvent.keyDown(second, { key: 'ArrowDown' })
    expect(document.activeElement).toBe(first)
    fireEvent.keyDown(first, { key: 'ArrowUp' })
    expect(document.activeElement).toBe(second)

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.queryByRole('menu')).toBeNull()
    expect(document.activeElement).toBe(menuButton)
  })

  it('moves the focus into a group\'s submenu, and Left or Escape bring it back to the group', async () => {
    answerWith([action({ group: 'Workflow' })], ROWS)
    renderPanel(panel(type, metaKey))
    await screen.findByText('Open task')

    fireEvent.click(within(rowOf('Open task')).getByRole('button', { name: 'Actions' }))
    const groupRow = within(screen.getByRole('menu')).getByRole('menuitem', { name: /Workflow/ })

    for (const back of ['ArrowLeft', 'Escape']) {
      fireEvent.keyDown(groupRow, { key: 'Enter' })
      const submenu = screen.getByRole('menu', { name: 'Workflow' })
      const item = within(submenu).getByRole('menuitem', { name: 'Close task' })
      expect(document.activeElement).toBe(item)

      fireEvent.keyDown(item, { key: back })
      expect(screen.queryByRole('menu', { name: 'Workflow' })).toBeNull()
      expect(document.activeElement).toBe(groupRow)
      // The row menu itself stays open.
      expect(screen.getByRole('menu', { name: 'Actions' })).toBeTruthy()
    }
  })

  it('offers a standalone inline action whatever the record\'s policy', async () => {
    answerWith([action({ standalone: true })], [{ id: 3, title: 'Denied task', _title: 'Denied task', _authorization: { authorizedToRunAction: false } }])
    renderPanel(panel(type, metaKey))
    await screen.findByText('Denied task')

    expect(rowButton('Denied task', 'Close task')!.disabled).toBe(false)
  })

  it('shows no row action when the related resource has no inline action', async () => {
    answerWith([action({ showInline: false, showOnIndex: true })], ROWS)
    renderPanel(panel(type, metaKey))
    await screen.findByText('Open task')

    expect(rowButton('Open task', 'Close task')).toBeNull()
    expect(within(rowOf('Open task')).queryByRole('button', { name: 'Actions' })).toBeNull()
  })
})

describe('belongs_to_many panel', () => {
  it('keeps its pivot actions and shows no resource row action', async () => {
    answerWith([action({})], ROWS.map((r) => ({ ...r, _pivot: {} })))
    renderPanel(panel('belongs_to_many', 'belongsToManyMeta'))
    await screen.findByText('Open task')

    expect(rowButton('Open task', 'Close task')).toBeNull()
  })
})
