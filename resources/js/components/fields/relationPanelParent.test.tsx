import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen, waitFor, fireEvent } from '@testing-library/react'
import {
  answerRelationRequests,
  relationField,
  renderOnPage,
  requestedUrls,
  startingWith,
} from '@/test-support/relationPanels'

// A relationship panel lists the related records of the record it belongs to.
// At the top level of a detail or edit page that record is the one in the URL.
// Inside a HasOne / MorphOne card it is the card's record, which the card
// provides through NestedParentContext. HasMany, MorphMany, BelongsToMany and
// MorphToMany ignored that context and kept reading the page URL, so on
// /resources/team-members/2 the panels of the firstManagedProject card
// (project 3) asked /api/resources/team-members/2/... for the project's
// tasks, tags and comments: 404 and empty panels.

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

// The table body plays no part in which record the panel reads from.
vi.mock('primereact/datatable', () => ({ DataTable: () => null }))
vi.mock('primereact/column', () => ({ Column: () => null }))

import { FieldDisplay, FieldInput } from './FieldRenderer'
import { NestedParentProvider } from './NestedParentContext'

interface Panel {
  type: string
  relationship: string
  relatedResource: string
  /** Schema key of the panel's meta. */
  metaKey: string
  /** API segment the panel reads from. */
  segment: string
  /** Lists pivot actions (BelongsToMany / MorphToMany). */
  pivot?: boolean
  /** Offers a Create button that names the parent in its via params. */
  creates?: boolean
}

// Every type the field registry renders as a relationship panel, the Through
// and OfMany variants included.
const PANELS: Panel[] = [
  { type: 'has_many', relationship: 'tasks', relatedResource: 'tasks', metaKey: 'hasManyMeta', segment: 'has-many', creates: true },
  { type: 'has_many_through', relationship: 'milestoneTasks', relatedResource: 'tasks', metaKey: 'hasManyMeta', segment: 'has-many' },
  { type: 'morph_many', relationship: 'comments', relatedResource: 'comments', metaKey: 'morphManyMeta', segment: 'morph-many', creates: true },
  { type: 'belongs_to_many', relationship: 'members', relatedResource: 'team-members', metaKey: 'belongsToManyMeta', segment: 'belongs-to-many', pivot: true },
  { type: 'morph_to_many', relationship: 'tags', relatedResource: 'tags', metaKey: 'morphToManyMeta', segment: 'morph-to-many', pivot: true },
  { type: 'has_one', relationship: 'brief', relatedResource: 'briefs', metaKey: 'hasOneMeta', segment: 'has-one', creates: true },
  { type: 'has_one_of_many', relationship: 'latestInvoice', relatedResource: 'invoices', metaKey: 'hasOneMeta', segment: 'has-one' },
  { type: 'has_one_through', relationship: 'accountManager', relatedResource: 'team-members', metaKey: 'hasOneMeta', segment: 'has-one' },
  { type: 'morph_one', relationship: 'coverImage', relatedResource: 'images', metaKey: 'morphOneMeta', segment: 'morph-one', creates: true },
  { type: 'morph_one_of_many', relationship: 'latestComment', relatedResource: 'comments', metaKey: 'morphOneMeta', segment: 'morph-one' },
]

function fieldFor(panel: Panel) {
  return relationField(panel.type, panel.relationship, panel.relatedResource, panel.metaKey)
}

/** Team member 2's detail page, showing the card of its project 3. */
function renderInsideProjectCard(panel: Panel) {
  return renderOnPage('/resources/team-members/2', '/resources/:resource/:id', (
    <NestedParentProvider value={{ resource: 'projects', id: 3 }}>
      <FieldDisplay field={fieldFor(panel)} value={null} resourceKey="projects" context="detail" />
    </NestedParentProvider>
  ))
}

beforeEach(() => {
  answerRelationRequests()
})

afterEach(() => {
  window.history.replaceState(null, '', '/')
})

describe.each(PANELS)('$type panel', (panel) => {
  const ofProject = `/api/resources/projects/3/${panel.segment}/${panel.relationship}`
  const ofTeamMember = `/api/resources/team-members/2/${panel.segment}/${panel.relationship}`

  it('reads the related records of the record it is nested in, not of the page record', async () => {
    renderInsideProjectCard(panel)

    await waitFor(() => expect(requestedUrls()).toEqual(expect.arrayContaining([startingWith(ofProject)])))
    if (panel.pivot) {
      await waitFor(() => expect(requestedUrls()).toContain(`${ofProject}/actions?context=detail`))
    }
    expect(requestedUrls().filter((url) => url.includes('/team-members/2/'))).toEqual([])
  })

  it('reads the related records of the page record at the top level of a detail page', async () => {
    renderOnPage('/resources/team-members/2', '/resources/:resource/:id', (
      <FieldDisplay field={fieldFor(panel)} value={null} resourceKey="team-members" context="detail" />
    ))

    await waitFor(() => expect(requestedUrls()).toEqual(expect.arrayContaining([startingWith(ofTeamMember)])))
    if (panel.pivot) {
      await waitFor(() => expect(requestedUrls()).toContain(`${ofTeamMember}/actions?context=detail`))
    }
    expect(requestedUrls().filter((url) => url.includes('/projects/'))).toEqual([])
  })
})

describe.each(PANELS.filter((panel) => panel.creates))('$type panel Create button', (panel) => {
  it('creates the related record for the record the panel is nested in', async () => {
    renderInsideProjectCard(panel)

    fireEvent.click(await screen.findByRole('button', { name: 'Create' }))

    const location = await screen.findByTestId('location')
    expect(location.textContent).toMatch(new RegExp(`^/resources/${panel.relatedResource}/create\\?`))
    expect(location.textContent).toContain('viaResource=projects&viaResourceId=3')
  })
})

describe.each(PANELS.filter((panel) => panel.pivot))('$type panel with no record to belong to', (panel) => {
  it('renders nothing and asks nothing on a page that names no record', async () => {
    // A Tool page: no route record and no provided one. Like Nova, no attach
    // panel before there is a record to attach to.
    const { container } = renderOnPage('/tools/reports', '/tools/:uriKey', (
      <FieldDisplay field={fieldFor(panel)} value={null} resourceKey="projects" context="detail" />
    ))

    await import(panel.type === 'belongs_to_many' ? './BelongsToManyField' : './MorphToManyField')
    await new Promise((resolve) => setTimeout(resolve, 100))

    expect(requestedUrls().filter((url) => url.includes(`/${panel.segment}/`))).toEqual([])
    // The lazy chunk's fallback (<div />) is gone and nothing replaced it.
    expect(container.innerHTML).toBe('')
  })
})

describe.each(PANELS.filter((panel) => panel.pivot))('$type form input', (panel) => {
  it('reads the related records of the record under edit', async () => {
    renderOnPage('/resources/projects/3/edit', '/resources/:resource/:id/edit', (
      <FieldInput
        field={fieldFor(panel)}
        value={null}
        onChange={() => {}}
        resourceKey="projects"
        recordId="3"
        context="update"
      />
    ))

    await waitFor(() => expect(requestedUrls()).toEqual(expect.arrayContaining([
      startingWith(`/api/resources/projects/3/${panel.segment}/${panel.relationship}`),
    ])))
  })
})
