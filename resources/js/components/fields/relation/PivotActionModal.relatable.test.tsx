import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * The pivot action modal renders the fields of an action a many-to-many
 * panel offers. Its relation pickers ask the panel's action for their
 * options, under the relationship the panel lists it for, instead of the
 * page's resource: an action declared on the field with ->actions() is not
 * one of the resource's own actions.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

import { PivotActionModal } from './PivotActionModal'
import type { ActionMeta } from '@/components/Actions/ActionModal'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

const action: ActionMeta = {
  uriKey: 'assign-owner', name: 'Assign owner', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: false,
  executionMode: 'bulk', standalone: false, sole: false, queued: false, withConfirmation: true,
  confirmText: null, confirmButtonText: null, cancelButtonText: null, modalSize: 'md',
  supportsDryRun: false, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: true, pivotLabel: null,
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
    url.endsWith('/assign-owner/fields') ? { data: { fields: [ownerField] } } : { data: [] },
  )
})

describe('PivotActionModal pickers', () => {
  it.each(['belongs-to-many/members', 'morph-to-many/tags'])(
    'ask the panel action for their options (%s)',
    async (panel) => {
      const actionsUrl = `/api/resources/projects/7/${panel}/actions`

      render(
        <QueryClientProvider client={new QueryClient()}>
          <ToastProvider>
            <MemoryRouter initialEntries={['/resources/projects/7']}>
              <Routes>
                <Route
                  path="/resources/:resource/:id"
                  element={
                    <PivotActionModal
                      actionsUrl={actionsUrl}
                      action={action}
                      selectedIds={[1]}
                      onSuccess={() => {}}
                      onClose={() => {}}
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
      expect(relatableCalls()[0].split('?')[0]).toBe(`${actionsUrl}/assign-owner/relatable/owner_id`)
    },
  )
})
