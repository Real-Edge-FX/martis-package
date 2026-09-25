import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import type { AuthorizationMetadata, FieldDefinition } from '@/types'

/*
 * The actions a relationship panel offers on its records.
 *
 * A Through panel offers no Create, as in Nova: the relationship endpoints
 * refuse a create through a Through relationship (HasManyThrough and
 * HasOneThrough emit `canCreate: false`, pinned on the PHP side by
 * ThroughRelationshipWritesTest). Its other actions are the base panel's.
 *
 * Every record a panel lists carries the related resource's policy answers
 * for it under `_authorization` (`serializeModel()` in the relationship
 * controllers). The resource index and the detail page already honour them;
 * the relationship panels showed View / Edit / Delete / Restore / Force
 * delete by the field's flags alone, so a user the policy denies saw an
 * action that then failed with a 403. A panel now leaves out, per record,
 * the actions its policy denies. A record without `_authorization` keeps
 * them: a missing answer is not a denial, as on the resource index.
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

registerDefaultFields()

const toolbarDefaults = {
  hideSearch: false,
  hideCreateButton: false,
  hidePerPageSelector: false,
  hideSoftDeleteToggle: false,
  hideViewAction: false,
  hideEditAction: false,
  hideDeleteAction: false,
  hideRestoreAction: false,
  hideForceDeleteAction: false,
}

/** A relationship field as its PHP class serialises it. */
function relationField(type: string, metaKey: string, meta: Record<string, unknown>): FieldDefinition {
  return {
    attribute: 'projects', label: 'Projects', type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: false, rules: [],
    relationship: 'projects',
    relatedResource: 'projects',
    [metaKey]: { perPage: 10, perPageOptions: [10], searchable: false, ...toolbarDefaults, ...meta },
  } as unknown as FieldDefinition
}

const HAS_MANY_META = { canCreate: true, canUpdate: true, canDelete: true }
const HAS_MANY_THROUGH_META = { canCreate: false, canUpdate: true, canDelete: true }

const titleField = {
  attribute: 'title', label: 'Title', type: 'text',
  nullable: false, readonly: false, required: true, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [],
}

const idField = {
  attribute: 'id', label: 'ID', type: 'id',
  nullable: false, readonly: true, required: false, sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: false, rules: [],
}

const ALLOWED: AuthorizationMetadata = {
  authorizedToView: true,
  authorizedToUpdate: true,
  authorizedToDelete: true,
  authorizedToReplicate: true,
  authorizedToRunAction: true,
  authorizedToRunDestructiveAction: true,
  authorizedToRestore: true,
  authorizedToForceDelete: true,
}

const DENIED: AuthorizationMetadata = {
  authorizedToView: false,
  authorizedToUpdate: false,
  authorizedToDelete: false,
  authorizedToReplicate: false,
  authorizedToRunAction: false,
  authorizedToRunDestructiveAction: false,
  authorizedToRestore: false,
  authorizedToForceDelete: false,
}

const TRASHED_AT = '2026-09-01T10:00:00.000000Z'

/** Answer the related schema, and the panel's records (the card's first one). */
function answerWith(records: Array<Record<string, unknown>>) {
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/projects/schema') {
      return Promise.resolve({ data: { fieldsForIndex: [idField, titleField], fieldsForDetail: [titleField], singularLabel: 'Project', softDeletes: true } })
    }
    if (path.includes('/actions')) {
      return Promise.resolve({ data: { actions: [] } })
    }
    if (path.includes('/has-one/') || path.includes('/morph-one/')) {
      return Promise.resolve({ data: records[0] ?? null, meta: {}, links: [] })
    }
    return Promise.resolve({
      data: records,
      meta: { current_page: 1, from: 1, last_page: 1, per_page: 10, to: records.length, total: records.length },
      links: { first: null, last: null, prev: null, next: null },
    })
  })
}

function renderPanel(field: FieldDefinition) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <NestedParentProvider value={{ resource: 'team-members', id: 2 }}>
          <FieldDisplay field={field} value={null} resourceKey="team-members" context="detail" />
        </NestedParentProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** The row actions (by tooltip) of the table row that shows `text`. */
function rowActions(text: string): string[] {
  const row = screen.getByText(text).closest('tr')
  if (row === null) throw new Error(`No table row shows "${text}"`)

  return Array.from(row.querySelectorAll('[data-pr-tooltip]')).map((el) => el.getAttribute('data-pr-tooltip') ?? '')
}

beforeEach(() => {
  apiGetMock.mockReset()
})

describe('Through panels: no Create, as in Nova', () => {
  it('offers no Create on a has_many_through panel, and its row actions', async () => {
    answerWith([
      { id: 1, title: 'Active project', _title: 'Active project', _authorization: ALLOWED },
      { id: 2, title: 'Trashed project', _title: 'Trashed project', deleted_at: TRASHED_AT, _authorization: ALLOWED },
    ])
    renderPanel(relationField('has_many_through', 'hasManyMeta', HAS_MANY_THROUGH_META))

    await screen.findByText('Trashed project')

    expect(screen.queryByRole('button', { name: 'Create' })).toBeNull()
    expect(rowActions('Active project')).toEqual(['View', 'Edit', 'Delete'])
    expect(rowActions('Trashed project')).toEqual(['View', 'Restore', 'Force delete'])
  })

  it('offers Create on a has_many panel over the same rows (control)', async () => {
    answerWith([{ id: 1, title: 'Active project', _title: 'Active project', _authorization: ALLOWED }])
    renderPanel(relationField('has_many', 'hasManyMeta', HAS_MANY_META))

    await screen.findByText('Active project')

    expect(screen.getByRole('button', { name: 'Create' })).toBeTruthy()
  })
})

describe.each([
  ['has_many', HAS_MANY_META],
  ['has_many_through', HAS_MANY_THROUGH_META],
])('%s panel: row actions follow each record\'s policy', (type, meta) => {
  it('leaves out, per row, the actions the policy denies', async () => {
    answerWith([
      { id: 1, title: 'Open project', _title: 'Open project', _authorization: ALLOWED },
      { id: 2, title: 'Locked project', _title: 'Locked project', _authorization: DENIED },
      { id: 3, title: 'Open trashed', _title: 'Open trashed', deleted_at: TRASHED_AT, _authorization: ALLOWED },
      { id: 4, title: 'Locked trashed', _title: 'Locked trashed', deleted_at: TRASHED_AT, _authorization: DENIED },
    ])
    renderPanel(relationField(type, 'hasManyMeta', meta))

    await screen.findByText('Locked trashed')

    expect(rowActions('Open project')).toEqual(['View', 'Edit', 'Delete'])
    expect(rowActions('Locked project')).toEqual([])
    expect(rowActions('Open trashed')).toEqual(['View', 'Restore', 'Force delete'])
    expect(rowActions('Locked trashed')).toEqual([])
  })

  it('keeps the actions of a record that carries no policy answers', async () => {
    answerWith([
      { id: 1, title: 'Plain project', _title: 'Plain project' },
      { id: 2, title: 'Plain trashed', _title: 'Plain trashed', deleted_at: TRASHED_AT },
    ])
    renderPanel(relationField(type, 'hasManyMeta', meta))

    await screen.findByText('Plain trashed')

    expect(rowActions('Plain project')).toEqual(['View', 'Edit', 'Delete'])
    expect(rowActions('Plain trashed')).toEqual(['View', 'Restore', 'Force delete'])
  })
})

describe.each([
  ['has_one', 'hasOneMeta'],
  ['has_one_through', 'hasOneMeta'],
  ['morph_one', 'morphOneMeta'],
])('%s card: Edit and Delete follow the record\'s policy', (type, metaKey) => {
  const meta = { canCreate: type !== 'has_one_through', canUpdate: true, canDelete: true }

  it('leaves out Edit and Delete when the policy denies them', async () => {
    answerWith([{ id: 7, title: 'Locked project', _title: 'Locked project', _authorization: DENIED }])
    renderPanel(relationField(type, metaKey, meta))

    await screen.findByText('Locked project')

    expect(screen.queryByRole('button', { name: 'Edit' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Delete' })).toBeNull()
  })

  it('offers Edit and Delete when the policy allows them', async () => {
    answerWith([{ id: 7, title: 'Open project', _title: 'Open project', _authorization: ALLOWED }])
    renderPanel(relationField(type, metaKey, meta))

    await screen.findByText('Open project')

    expect(screen.getByRole('button', { name: 'Edit' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Delete' })).toBeTruthy()
  })
})

/** One answer granted and the rest denied, or the reverse. */
function only(granted: Array<keyof AuthorizationMetadata>, value = true): AuthorizationMetadata {
  const base = value ? DENIED : ALLOWED
  return { ...base, ...Object.fromEntries(granted.map((key) => [key, value])) } as AuthorizationMetadata
}

const MORPH_MANY_META = { canCreate: true, canUpdate: true, canDelete: true }

// Mixed answers: a version that mixed up two abilities (Edit read from
// authorizedToDelete, Restore from authorizedToForceDelete, …) passes the
// all-or-nothing rows above and fails here.
describe.each([
  ['has_many', 'hasManyMeta', HAS_MANY_META],
  ['has_many_through', 'hasManyMeta', HAS_MANY_THROUGH_META],
  ['morph_many', 'morphManyMeta', MORPH_MANY_META],
])('%s panel: each row action reads its own ability', (type, metaKey, meta) => {
  it('shows exactly the actions each row is allowed', async () => {
    answerWith([
      { id: 1, title: 'Delete only', _title: 'Delete only', _authorization: only(['authorizedToView', 'authorizedToDelete']) },
      { id: 2, title: 'Update only', _title: 'Update only', _authorization: only(['authorizedToView', 'authorizedToUpdate']) },
      { id: 3, title: 'No view', _title: 'No view', _authorization: only(['authorizedToView'], false) },
      { id: 4, title: 'Force only', _title: 'Force only', deleted_at: TRASHED_AT, _authorization: only(['authorizedToView', 'authorizedToForceDelete']) },
      { id: 5, title: 'Restore only', _title: 'Restore only', deleted_at: TRASHED_AT, _authorization: only(['authorizedToView', 'authorizedToRestore']) },
    ])
    renderPanel(relationField(type, metaKey, meta))

    await screen.findByText('Restore only')

    expect(rowActions('Delete only')).toEqual(['View', 'Delete'])
    expect(rowActions('Update only')).toEqual(['View', 'Edit'])
    expect(rowActions('No view')).toEqual(['Edit', 'Delete'])
    expect(rowActions('Force only')).toEqual(['View', 'Force delete'])
    expect(rowActions('Restore only')).toEqual(['View', 'Restore'])
  })

  it('links the id column only for a record the user may view', async () => {
    answerWith([
      { id: 11, title: 'Viewable', _title: 'Viewable', _authorization: ALLOWED },
      { id: 12, title: 'Hidden', _title: 'Hidden', _authorization: only(['authorizedToView'], false) },
    ])
    renderPanel(relationField(type, metaKey, meta))

    await screen.findByText('Hidden')

    expect(screen.getByText('Viewable').closest('tr')?.querySelector('td a[href$="/11"]')).not.toBeNull()
    expect(screen.getByText('Hidden').closest('tr')?.querySelector('a[href$="/12"]')).toBeNull()
  })
})

// The pivot panels list the related records without View / Edit / Delete of
// their own (Detach and the pivot edit take their place), but a trashed
// related record keeps Restore and Force delete, by its policy and the
// field's hide flags.
describe.each([
  ['belongs_to_many', 'belongsToManyMeta'],
  ['morph_to_many', 'morphToManyMeta'],
])('%s panel: trashed related records', (type, metaKey) => {
  const meta = { canAttach: true, canDetach: true }

  it('offers Restore and Force delete by each record\'s policy', async () => {
    answerWith([
      { id: 1, title: 'Force only', _title: 'Force only', deleted_at: TRASHED_AT, _pivot: {}, _authorization: only(['authorizedToForceDelete']) },
      { id: 2, title: 'Restore only', _title: 'Restore only', deleted_at: TRASHED_AT, _pivot: {}, _authorization: only(['authorizedToRestore']) },
      { id: 3, title: 'Neither', _title: 'Neither', deleted_at: TRASHED_AT, _pivot: {}, _authorization: DENIED },
    ])
    renderPanel(relationField(type, metaKey, meta))

    await screen.findByText('Neither')

    expect(rowActions('Force only')).toEqual(expect.arrayContaining(['Force delete']))
    expect(rowActions('Force only')).not.toContain('Restore')
    expect(rowActions('Restore only')).toEqual(expect.arrayContaining(['Restore']))
    expect(rowActions('Restore only')).not.toContain('Force delete')
    expect(rowActions('Neither').filter((a) => a === 'Restore' || a === 'Force delete')).toEqual([])
  })

  it('honours hideRestoreAction() and hideForceDeleteAction()', async () => {
    answerWith([{ id: 1, title: 'Trashed', _title: 'Trashed', deleted_at: TRASHED_AT, _pivot: {}, _authorization: ALLOWED }])
    renderPanel(relationField(type, metaKey, { ...meta, hideRestoreAction: true, hideForceDeleteAction: true }))

    await screen.findByText('Trashed')

    expect(rowActions('Trashed').filter((a) => a === 'Restore' || a === 'Force delete')).toEqual([])
  })
})
