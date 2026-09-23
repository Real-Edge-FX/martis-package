import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import type { OverrideProps, ResourceRecord, ResourceSchema, TabGroupDefinition } from '@/types'
import {
  answerRelationRequests,
  relationField,
  renderOnPage,
  requestedUrls,
  startingWith,
} from '@/test-support/relationPanels'

// A record drawer shows a record the page URL does not name: an action run on
// another record's page, a lens row or an index row opens it. The relationship
// panels inside it read the page URL, so they listed the related records of
// the page's record, or of none. Each drawer now provides the record it shows
// as the parent of every relationship panel inside it.

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

vi.mock('primereact/datatable', () => ({ DataTable: () => null }))
vi.mock('primereact/column', () => ({ Column: () => null }))

// The shell's portal, focus handling and animation are not under test.
vi.mock('./DrawerShell', () => ({
  DrawerShell: ({ children, footer }: { children: ReactNode; footer?: ReactNode }) => (
    <div>
      {children}
      {footer}
    </div>
  ),
}))

import { DrawerDetail } from './DrawerDetail'
import { DrawerQuick } from './DrawerQuick'
import { DrawerUpdate } from './DrawerUpdate'

const tasks = relationField('has_many', 'tasks', 'tasks', 'hasManyMeta')
const members = relationField('belongs_to_many', 'members', 'team-members', 'belongsToManyMeta')

const project = {
  id: 3,
  _title: 'Apollo',
  _resource: { uriKey: 'projects', label: 'Projects', singularLabel: 'Project' },
  _authorization: { authorizedToView: true, authorizedToUpdate: true, authorizedToDelete: true },
} as unknown as ResourceRecord

function drawerProps(schema: Record<string, unknown>): OverrideProps {
  return {
    schema: {
      uriKey: 'projects',
      label: 'Projects',
      singularLabel: 'Project',
      softDeletes: false,
      confirmUnsavedChanges: false,
      fieldsForDetail: [],
      fieldsForUpdate: [],
      ...schema,
    } as unknown as ResourceSchema,
    resource: 'projects',
    params: {},
    record: project,
    recordId: '3',
    navigate: vi.fn(),
    onClose: vi.fn(),
    onCreated: vi.fn(),
    onUpdated: vi.fn(),
    onDeleted: vi.fn(),
    onEdit: vi.fn(),
    onView: vi.fn(),
    addToast: vi.fn(),
  }
}

/** Team member 2's detail page, where an action opened project 3's drawer. */
function renderOnTeamMemberPage(drawer: ReactNode) {
  return renderOnPage('/resources/team-members/2', '/resources/:resource/:id', drawer)
}

async function expectPanelOfProject(segment: string, relationship: string) {
  await waitFor(() => expect(requestedUrls()).toEqual(expect.arrayContaining([
    startingWith(`/api/resources/projects/3/${segment}/${relationship}`),
  ])))
  expect(requestedUrls().filter((url) => url.includes('/team-members/2/'))).toEqual([])
}

beforeEach(() => {
  answerRelationRequests()
})

afterEach(() => {
  window.history.replaceState(null, '', '/')
})

describe('relationship panels inside a record drawer', () => {
  it('DrawerDetail lists the related records of the record it shows', async () => {
    renderOnTeamMemberPage(<DrawerDetail {...drawerProps({ fieldsForDetail: [tasks] })} />)

    await expectPanelOfProject('has-many', 'tasks')
  })

  it('DrawerDetail does the same for a panel inside a tab group', async () => {
    const tabGroup = {
      type: 'tab_group',
      tabs: [{ title: 'Work', fields: [tasks] }],
    } as unknown as TabGroupDefinition
    renderOnTeamMemberPage(<DrawerDetail {...drawerProps({ fieldsForDetail: [tabGroup] })} />)

    await expectPanelOfProject('has-many', 'tasks')
  })

  it('DrawerUpdate lists the related records of the record it edits', async () => {
    renderOnTeamMemberPage(<DrawerUpdate {...drawerProps({ fieldsForUpdate: [members] })} />)

    await expectPanelOfProject('belongs-to-many', 'members')
  })

  it('DrawerQuick lists the related records of the record it shows', async () => {
    renderOnTeamMemberPage(<DrawerQuick {...drawerProps({ fieldsForPreview: [tasks] })} />)

    await expectPanelOfProject('has-many', 'tasks')
  })
})
