import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen, waitFor } from '@testing-library/react'
import { useState, type ComponentType } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { FieldDefinition } from '@/types'
import type { FieldInputProps } from './types'

/*
 * A relation picker (`BelongsTo`, `MorphTo`, `Tag`) loads its options from
 * `/api/resources/{resource}/{id}/relatable/{attribute}`, and the server
 * reads the field from the form that URL names: the update form of record
 * `{id}`, the create forms for `_`. The pickers filled whatever the input
 * did not say from the page's route, so a create form nested in an edit
 * page (the inline-create modal) sent the page's record id, and an Action's
 * fields asked the page's resource for a field the Action declares. The
 * pivot fields of a relationship's modals ask the panel instead, and a field
 * of a Repeater row names the row the server reads it from.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

import { BelongsToFieldInput } from './BelongsToField'
import { MorphToFieldInput } from './MorphToField'
import { TagFieldInput } from './TagField'

const base = {
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [],
}

interface Picker {
  Input: ComponentType<FieldInputProps>
  field: FieldDefinition
  /** Opens the options dropdown, which fetches the first page. */
  open: (container: HTMLElement) => void
}

const pickers: Record<string, Picker> = {
  BelongsTo: {
    Input: BelongsToFieldInput,
    field: { ...base, attribute: 'team_id', label: 'Team', type: 'belongs_to', relatedResource: 'teams' } as unknown as FieldDefinition,
    open: (container) => fireEvent.click(container.querySelector('.martis-belongs-to-trigger') as HTMLElement),
  },
  MorphTo: {
    Input: MorphToFieldInput,
    field: { ...base, attribute: 'owner', label: 'Owner', type: 'morph_to', morphTypes: [{ value: 'teams', label: 'Team' }] } as unknown as FieldDefinition,
    open: (container) => {
      fireEvent.change(container.querySelector('select') as HTMLSelectElement, { target: { value: 'teams' } })
      fireEvent.click(container.querySelector('.martis-belongs-to-trigger') as HTMLElement)
    },
  },
  Tag: {
    Input: TagFieldInput,
    field: { ...base, attribute: 'tags', label: 'Tags', type: 'tag', relatedResource: 'tags' } as unknown as FieldDefinition,
    open: () => fireEvent.click(screen.getByRole('button', { name: 'Add Tags' })),
  },
}

type Scope = Pick<FieldInputProps, 'resourceKey' | 'recordId' | 'context' | 'actionEndpoint' | 'pivotEndpoint' | 'repeaterRow'>

/** A form around the input that stores what it emits, as the real forms do. */
function Form({ picker, scope }: { picker: Picker; scope: Scope }) {
  const [value, setValue] = useState<unknown>(null)
  const { Input } = picker
  return <Input field={picker.field} value={value} onChange={setValue} {...scope} />
}

/** Renders the input on a page of the SPA and returns the first relatable request. */
async function relatableRequestOf(picker: Picker, page: string, scope: Scope): Promise<URL> {
  const { container } = render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={[page]}>
        <Routes>
          <Route path="/resources/:resource/create" element={<Form picker={picker} scope={scope} />} />
          <Route path="/resources/:resource/:id" element={<Form picker={picker} scope={scope} />} />
          <Route path="/resources/:resource/:id/edit" element={<Form picker={picker} scope={scope} />} />
          <Route path="/tools/:uriKey" element={<Form picker={picker} scope={scope} />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  picker.open(container)

  await waitFor(() => expect(apiGetMock).toHaveBeenCalled())
  return new URL(String(apiGetMock.mock.calls[0][0]), 'http://martis.test')
}

/** The path of the first relatable request (no query string). */
async function relatablePathOf(picker: Picker, page: string, scope: Scope): Promise<string> {
  return (await relatableRequestOf(picker, page, scope)).pathname
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({ data: [] })
})

describe.each(Object.entries(pickers))('%s picker relatable endpoint', (_name, picker) => {
  const attribute = picker.field.attribute

  it('sends `_` from a create form nested in an edit page (the inline-create modal)', async () => {
    const path = await relatablePathOf(picker, '/resources/projects/7/edit', { resourceKey: 'clients', context: 'create' })

    expect(path).toBe(`/api/resources/clients/_/relatable/${attribute}`)
  })

  it('asks the Action for a field the input renders in an Action modal', async () => {
    const path = await relatablePathOf(picker, '/resources/projects/7', {
      actionEndpoint: '/api/resources/projects/actions/assign-owner',
      context: 'create',
    })

    expect(path).toBe(`/api/resources/projects/actions/assign-owner/relatable/${attribute}`)
  })

  it('does not send the page record id for another resource', async () => {
    // The route id names a project; the input is scoped to clients.
    const path = await relatablePathOf(picker, '/resources/projects/7/edit', { resourceKey: 'clients' })

    expect(path).toBe(`/api/resources/clients/_/relatable/${attribute}`)
  })

  it('keeps the record of an update form', async () => {
    const path = await relatablePathOf(picker, '/resources/projects/7/edit', { resourceKey: 'projects', recordId: 7, context: 'update' })

    expect(path).toBe(`/api/resources/projects/7/relatable/${attribute}`)
  })

  it('keeps a record id the host passes explicitly', async () => {
    // A Tool form bound to a record renders `FieldsForm` in its default create context.
    const path = await relatablePathOf(picker, '/tools/editor', { resourceKey: 'projects', recordId: 42, context: 'create' })

    expect(path).toBe(`/api/resources/projects/42/relatable/${attribute}`)
  })

  it('falls back to the page record when the input names no scope', async () => {
    const path = await relatablePathOf(picker, '/resources/projects/7/edit', {})

    expect(path).toBe(`/api/resources/projects/7/relatable/${attribute}`)
  })

  it('asks the relationship panel for a pivot field (attach and pivot edit modals)', async () => {
    const path = await relatablePathOf(picker, '/resources/projects/7', {
      pivotEndpoint: '/api/resources/projects/7/belongs-to-many/members/pivot-fields/5',
      context: 'update',
    })

    expect(path).toBe(`/api/resources/projects/7/belongs-to-many/members/pivot-fields/5/relatable/${attribute}`)
  })

  it('names the Repeater row the input renders in', async () => {
    const url = await relatableRequestOf(picker, '/resources/projects/7/edit', {
      resourceKey: 'projects',
      recordId: 7,
      context: 'update',
      repeaterRow: { repeater: 'lines', repeatable: 'product-line' },
    })

    expect(url.pathname).toBe(`/api/resources/projects/7/relatable/${attribute}`)
    expect(url.searchParams.get('repeater')).toBe('lines')
    expect(url.searchParams.get('repeatable')).toBe('product-line')
    expect(url.searchParams.get('per_page')).not.toBeNull()
  })
})
