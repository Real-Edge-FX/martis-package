import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * The inline-create modal renders the create form of the related resource on
 * top of another resource's page. Its pickers used to send the page's record
 * id with the modal's resource, so on an edit page the server read the update
 * form of whichever record of the modal's resource carried that id, and a
 * picker declared only on the modal resource's create forms answered 404.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

import { InlineCreateModal } from './InlineCreateModal'
import { registerDefaultFields } from './fields/FieldRenderer'

registerDefaultFields()

const teamField = {
  attribute: 'team_id', label: 'Team', type: 'belongs_to', relatedResource: 'teams',
  nullable: true, readonly: false, required: false, sortable: false, searchable: false,
  showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
}

function relatableCalls(): string[] {
  return apiGetMock.mock.calls.map((call) => String(call[0])).filter((url) => url.includes('/relatable/'))
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockImplementation(async (url: string) =>
    url === '/api/resources/clients/inline-create-schema'
      ? { data: { fields: [teamField], singularLabel: 'Client', label: 'Clients' } }
      : { data: [] },
  )
})

describe('InlineCreateModal pickers', () => {
  it('load their options from the create forms of the modal resource on an edit page', async () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ToastProvider>
          <MemoryRouter initialEntries={['/resources/projects/7/edit']}>
            <Routes>
              <Route
                path="/resources/:resource/:id/edit"
                element={<InlineCreateModal relatedResource="clients" open onClose={() => {}} onCreated={() => {}} />}
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
    expect(relatableCalls()[0].split('?')[0]).toBe('/api/resources/clients/_/relatable/team_id')
  })
})
