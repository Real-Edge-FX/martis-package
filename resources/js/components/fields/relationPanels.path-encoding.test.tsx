import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { Children, isValidElement, type ReactNode } from 'react'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/lib/api'
import type { FieldDefinition } from '@/types'
import { relationField, renderOnPage, requestedUrls } from '@/test-support/relationPanels'
import { apiSegments, resolveApiPath } from '@/test-support/apiPaths'

/*
 * F011, F012 and the relationship panels: a panel names its parent record
 * (the route's, or the enclosing card's) and a related record in the paths of
 * its fetch, delete, detach, restore and force-delete requests, and the
 * parent in the query string of its Create and Edit links. Those values come
 * from the route, from a stored key, from the API: raw, a value with `../` or
 * `?` or `&` rewrote the request or injected a parameter. Every value is one
 * encoded segment (or one encoded query value).
 */

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

vi.mock('primereact/datatable', () => ({
  DataTable: ({ value, children }: { value?: Array<Record<string, unknown>>; children?: ReactNode }) => (
    <div>
      {(value ?? []).map((row) => (
        <div key={String(row.id)}>
          {Children.toArray(children).map((column, index) => {
            const body = isValidElement<{ body?: (row: unknown) => ReactNode }>(column) ? column.props.body : undefined
            return <div key={index}>{body ? body(row) : null}</div>
          })}
        </div>
      ))}
    </div>
  ),
}))
vi.mock('primereact/column', () => ({ Column: () => null }))

import { FieldDisplay, registerDefaultFields } from './FieldRenderer'
import { NestedParentProvider } from './NestedParentContext'

registerDefaultFields()

const PARENT = '../users/5?x=1'
const PARENT_SEGMENT = '..%252Fusers%252F5%3Fx%3D1'
const RELATED = '../users/6'
const RELATED_SEGMENT = '..%252Fusers%252F6'

interface Panel {
  type: string
  relationship: string
  relatedResource: string
  metaKey: string
  segment: string
  pivot?: boolean
  creates?: boolean
}

const PANELS: Panel[] = [
  { type: 'has_many', relationship: 'tasks', relatedResource: 'tasks', metaKey: 'hasManyMeta', segment: 'has-many', creates: true },
  { type: 'morph_many', relationship: 'comments', relatedResource: 'comments', metaKey: 'morphManyMeta', segment: 'morph-many', creates: true },
  { type: 'belongs_to_many', relationship: 'members', relatedResource: 'team-members', metaKey: 'belongsToManyMeta', segment: 'belongs-to-many', pivot: true },
  { type: 'morph_to_many', relationship: 'tags', relatedResource: 'tags', metaKey: 'morphToManyMeta', segment: 'morph-to-many', pivot: true },
  { type: 'has_one', relationship: 'brief', relatedResource: 'briefs', metaKey: 'hasOneMeta', segment: 'has-one', creates: true },
  { type: 'morph_one', relationship: 'coverImage', relatedResource: 'images', metaKey: 'morphOneMeta', segment: 'morph-one', creates: true },
]

function fieldFor(panel: Panel): FieldDefinition {
  return relationField(panel.type, panel.relationship, panel.relatedResource, panel.metaKey)
}

/** Answer the panel's GETs: the schema, a page holding one related row, the pivot actions. */
function answer(options: { trashed?: boolean; softDeletes?: boolean; empty?: boolean } = {}): void {
  vi.mocked(api.get).mockReset()
  vi.mocked(api.get).mockImplementation(((url: string) => {
    const page = { current_page: 1, from: 1, last_page: 1, per_page: 10, to: 1, total: 1 }
    const links = { first: null, last: null, prev: null, next: null }
    if (url.endsWith('/schema')) {
      return Promise.resolve({
        data: { fieldsForIndex: [], fieldsForDetail: [], singularLabel: 'Record', softDeletes: options.softDeletes === true },
      })
    }
    if (url.includes('/actions?')) return Promise.resolve({ data: { actions: [] } })
    if (/\/(has-one|morph-one)\//.test(url)) {
      // A one-to-one card offers Create only while it holds no record.
      if (options.empty) return Promise.resolve({ data: null })
      return Promise.resolve({ data: { id: RELATED, _title: 'Related', _authorization: {} }, meta: {} })
    }
    return Promise.resolve({
      data: [{ id: RELATED, _title: 'Related', _pivot: {}, ...(options.trashed ? { deleted_at: '2026-01-01' } : {}) }],
      meta: page,
      links,
    })
  }) as unknown as typeof api.get)
  vi.mocked(api.delete).mockReset()
  vi.mocked(api.delete).mockResolvedValue({} as never)
  vi.mocked(api.put).mockReset()
  vi.mocked(api.put).mockResolvedValue({} as never)
}

beforeEach(() => {
  answer()
})

afterEach(() => {
  window.history.replaceState(null, '', '/')
})

function renderNested(panel: Panel, parent = { resource: 'projects', id: PARENT }) {
  return renderOnPage('/resources/team-members/2', '/resources/:resource/:id', (
    <NestedParentProvider value={parent}>
      <FieldDisplay field={fieldFor(panel)} value={null} resourceKey="projects" context="detail" />
    </NestedParentProvider>
  ))
}

describe.each(PANELS)('$type panel — a parent id that traverses stays one path segment', (panel) => {
  it('reads the related records through the parent resource, never another endpoint', async () => {
    renderNested(panel)

    await waitFor(() => expect(requestedUrls().some((url) => url.includes(`/${panel.segment}/`))).toBe(true))
    const request = requestedUrls().find((url) => url.includes(`/${panel.segment}/`))!
    expect(apiSegments(request).slice(0, 5)).toEqual(['resources', 'projects', PARENT_SEGMENT, panel.segment, panel.relationship])
    expect(resolveApiPath(request).searchParams.has('x')).toBe(false)
    if (panel.pivot) {
      await waitFor(() => expect(requestedUrls().some((url) => url.includes('/actions?'))).toBe(true))
      const actions = requestedUrls().find((url) => url.includes('/actions?'))!
      expect(apiSegments(actions)).toEqual(['resources', 'projects', PARENT_SEGMENT, panel.segment, panel.relationship, 'actions'])
    }
  })
})

describe.each(PANELS.filter((panel) => panel.creates))('$type panel Create button — a parent id with an ampersand adds no parameter', (panel) => {
  it('names the parent in one encoded query value', async () => {
    answer({ empty: true })
    renderNested(panel, { resource: 'projects', id: '3&viaRelationshipType=morph-many&redirectMode=x' })

    fireEvent.click(await screen.findByRole('button', { name: 'Create' }))

    const location = (await screen.findByTestId('location')).textContent ?? ''
    const query = new URLSearchParams(location.slice(location.indexOf('?') + 1))
    expect(query.get('viaResource')).toBe('projects')
    expect(query.get('viaResourceId')).toBe('3&viaRelationshipType=morph-many&redirectMode=x')
    expect(query.get('viaRelationship')).toBe(panel.relationship)
    expect(query.getAll('viaRelationshipType').filter((type) => type === 'morph-many')).toEqual([])
    expect(query.get('redirectMode')).not.toBe('x')
  })
})

describe('has_many panel — a related record key that traverses stays one path segment', () => {
  it('deletes the clicked related record through the parent endpoint', async () => {
    const { container } = renderNested(PANELS[0]!, { resource: 'projects', id: '3' })

    const trash = await waitFor(() => {
      const el = container.querySelector('[data-pr-tooltip="Delete"]')
      expect(el).not.toBeNull()
      return el as HTMLElement
    })
    fireEvent.click(trash)
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete permanently' }))

    await waitFor(() => expect(api.delete).toHaveBeenCalledTimes(1))
    expect(apiSegments(vi.mocked(api.delete).mock.calls[0]![0] as string)).toEqual(['resources', 'projects', '3', 'has-many', 'tasks', RELATED_SEGMENT])
  })

  it('restores and force deletes a trashed related record through its own resource', async () => {
    answer({ trashed: true, softDeletes: true })
    const { container } = renderNested(PANELS[0]!, { resource: 'projects', id: '3' })

    const restore = await waitFor(() => {
      const el = container.querySelector('[data-pr-tooltip="Restore"]')
      expect(el).not.toBeNull()
      return el as HTMLElement
    })
    fireEvent.click(restore)
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Restore' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(1))
    expect(apiSegments(vi.mocked(api.put).mock.calls[0]![0] as string)).toEqual(['resources', 'tasks', RELATED_SEGMENT, 'restore'])

    fireEvent.click(container.querySelector('[data-pr-tooltip="Force delete"]') as HTMLElement)
    const dialogs = await screen.findAllByRole('dialog')
    fireEvent.click(within(dialogs[dialogs.length - 1]!).getByRole('button', { name: 'Delete permanently' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledTimes(1))
    expect(apiSegments(vi.mocked(api.delete).mock.calls[0]![0] as string)).toEqual(['resources', 'tasks', RELATED_SEGMENT, 'force'])
  })
})

describe('belongs_to_many panel — a related record key that traverses stays one path segment', () => {
  it('detaches the clicked related record through the parent endpoint', async () => {
    const { container } = renderNested(PANELS[2]!, { resource: 'projects', id: '3' })

    const detach = await waitFor(() => {
      const el = container.querySelector('[data-pr-tooltip="Detach"]')
      expect(el).not.toBeNull()
      return el as HTMLElement
    })
    fireEvent.click(detach)
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Detach' }))

    await waitFor(() => expect(api.delete).toHaveBeenCalledTimes(1))
    expect(apiSegments(vi.mocked(api.delete).mock.calls[0]![0] as string)).toEqual([
      'resources', 'projects', '3', 'belongs-to-many', 'members', RELATED_SEGMENT, 'detach',
    ])
  })
})
