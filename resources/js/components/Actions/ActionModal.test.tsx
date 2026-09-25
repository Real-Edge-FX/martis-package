import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * The action modal renders the fields an Action declares. Its relation
 * pickers used to ask the page's resource (and record) for their options, so
 * an attribute the resource does not declare answered 404 and one it does
 * declare listed the resource's options instead of the Action's. They now
 * ask the Action, under the resource the modal runs it on.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

import { ActionModal, type ActionMeta } from './ActionModal'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

const action: ActionMeta = {
  uriKey: 'assign-owner', name: 'Assign owner', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: false,
  executionMode: 'bulk', standalone: false, sole: false, queued: false, withConfirmation: true,
  confirmText: null, confirmButtonText: null, cancelButtonText: null, modalSize: 'md',
  supportsDryRun: false, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: false, pivotLabel: null,
}

const ownerField = {
  attribute: 'owner_id', label: 'Owner', type: 'belongs_to', relatedResource: 'users',
  nullable: true, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
}

function relatableCalls(): string[] {
  return apiGetMock.mock.calls.map((call) => String(call[0])).filter((url) => url.includes('/relatable/'))
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockImplementation(async (url: string) =>
    url.endsWith('/actions/assign-owner/fields') ? { data: { fields: [ownerField] } } : { data: [] },
  )
})

describe('ActionModal pickers', () => {
  it.each([
    ['the page resource', 'projects'],
    ['another resource listed on the page (a relation panel)', 'tasks'],
  ])('ask the Action for their options when it runs on %s', async (_label, resource) => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ToastProvider>
          <MemoryRouter initialEntries={['/resources/projects/7']}>
            <Routes>
              <Route
                path="/resources/:resource/:id"
                element={
                  <ActionModal
                    resource={resource}
                    action={action}
                    selectedIds={[1]}
                    visible
                    onHide={() => {}}
                    onSuccess={() => {}}
                  />
                }
              />
            </Routes>
          </MemoryRouter>
        </ToastProvider>
      </QueryClientProvider>,
    )

    const trigger = await waitFor(() => {
      const el = document.body.querySelector('.martis-belongs-to-trigger')
      expect(el).not.toBeNull()
      return el as HTMLElement
    })
    fireEvent.click(trigger)

    await waitFor(() => expect(relatableCalls()).toHaveLength(1))
    expect(relatableCalls()[0].split('?')[0]).toBe(`/api/resources/${resource}/actions/assign-owner/relatable/owner_id`)
  })
})
