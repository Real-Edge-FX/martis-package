import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useState } from 'react'
import { render, fireEvent, waitFor, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
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
import { componentRegistry } from '@/lib/componentRegistry'
import type { FieldInputProps } from './fields/types'

registerDefaultFields()

// A custom input (registered the way a consumer registers one) that keeps
// what the user types in its own state, read from `value` once, at mount.
function MountDraftInput({ field, value, onChange }: FieldInputProps) {
  const [draft, setDraft] = useState(() => String(value ?? ''))
  return (
    <input
      id={field.attribute}
      value={draft}
      onChange={(e) => {
        setDraft(e.target.value)
        onChange(e.target.value)
      }}
    />
  )
}
componentRegistry.registerFieldInput('mount_draft', MountDraftInput)

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

/*
 * The modal cleared what the user had typed when it opened again, one
 * render after its fields had mounted with those values: an input that reads
 * its value when it mounts kept the text of the record the user had given
 * up on. The modal now clears its form as it closes, so its fields always
 * mount on an empty form.
 */
describe('InlineCreateModal opened again', () => {
  const codeField = {
    attribute: 'code', label: 'Code', type: 'mount_draft',
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
  }

  function Host() {
    const [open, setOpen] = useState(false)
    return (
      <>
        <button type="button" onClick={() => setOpen(true)}>New author</button>
        <InlineCreateModal relatedResource="authors" open={open} onClose={() => setOpen(false)} onCreated={() => setOpen(false)} />
      </>
    )
  }

  const code = () => document.getElementById('code') as HTMLInputElement | null

  it('starts from an empty form after the user closed it with something typed', async () => {
    apiGetMock.mockImplementation(async (url: string) =>
      url === '/api/resources/authors/inline-create-schema'
        ? { data: { fields: [codeField], singularLabel: 'Author', label: 'Authors' } }
        : { data: [] },
    )
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ToastProvider>
          <MemoryRouter>
            <Host />
          </MemoryRouter>
        </ToastProvider>
      </QueryClientProvider>,
    )
    fireEvent.click(screen.getByRole('button', { name: 'New author' }))
    await waitFor(() => expect(code()).not.toBeNull())
    fireEvent.change(code()!, { target: { value: 'Given up' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Cancel' })[0])
    await waitFor(() => expect(code()).toBeNull())

    fireEvent.click(screen.getByRole('button', { name: 'New author' }))

    await waitFor(() => expect(code()).not.toBeNull())
    expect(code()!.value).toBe('')
  })
})
