import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, fireEvent, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { FieldDefinition } from '@/types'
import type { FieldInputProps } from './types'
import { componentRegistry } from '@/lib/componentRegistry'

/*
 * A Repeater renders each row's fields with the resource and record of its
 * form, but forced `context="update"` on them and dropped the rest of the
 * form's scope. A server-backed row field then asked the form's endpoints for
 * an attribute only the row declares: a BelongsTo, MorphTo or Tag picker read
 * `/api/resources/{resource}/{id}/relatable/{attribute}` (404, empty picker;
 * the page record's update form on a create form nested in a detail page), a
 * remote Select sent `context=update` with no record on a create form (422).
 * Row fields now get the form's own context and scope, plus the row they
 * render in (`repeater` + `repeatable`), which the server reads the field
 * from.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

import { RepeaterFieldInput } from './RepeaterField'
import { registerDefaultFields } from './FieldRenderer'

registerDefaultFields()

const base = {
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [],
}

const productPicker = { ...base, attribute: 'product_id', label: 'Product', type: 'belongs_to', relatedResource: 'products' }
const unitSelect = { ...base, attribute: 'unit', label: 'Unit', type: 'select', options: [], searchableOptions: true, remoteOptionsSearch: true }

function repeaterField(rowFields: Record<string, unknown>[] = [productPicker, unitSelect]): FieldDefinition {
  return {
    ...base,
    attribute: 'lines', label: 'Lines', type: 'repeater', storage: 'json',
    repeatables: [
      { shortName: 'product-line', uniqueKey: 'product-line', label: 'Product line', fields: rowFields },
      { shortName: 'service-line', uniqueKey: 'service-line', label: 'Service line', fields: [productPicker] },
    ],
  } as unknown as FieldDefinition
}

const rows = [
  { id: 'a', type: 'product-line', fields: { product_id: null, unit: null } },
  { id: 'b', type: 'service-line', fields: { product_id: null } },
]

type Scope = Pick<FieldInputProps, 'resourceKey' | 'recordId' | 'context' | 'actionEndpoint' | 'pivotEndpoint' | 'toolKey'>

/** Renders the Repeater on a page of the SPA, as a form with that scope would. */
function renderRepeater(page: string, scope: Scope, field: FieldDefinition = repeaterField()) {
  const input = <RepeaterFieldInput field={field} value={rows} onChange={() => {}} {...scope} />
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={[page]}>
        <Routes>
          <Route path="/resources/:resource/create" element={input} />
          <Route path="/resources/:resource/:id" element={input} />
          <Route path="/resources/:resource/:id/edit" element={input} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function requested(fragment: string): URL[] {
  return apiGetMock.mock.calls
    .map((call) => String(call[0]))
    .filter((url) => url.includes(fragment))
    .map((url) => new URL(url, 'http://martis.test'))
}

/** Opens the product picker of the row at `row` and returns its relatable request. */
async function relatableRequestOf(container: HTMLElement, row: number): Promise<URL> {
  fireEvent.click(container.querySelectorAll('.martis-belongs-to-trigger')[row] as HTMLElement)
  await waitFor(() => expect(requested('/relatable/')).toHaveLength(1))
  return requested('/relatable/')[0]
}

function rowOf(url: URL): Record<string, string | null> {
  return { repeater: url.searchParams.get('repeater'), repeatable: url.searchParams.get('repeatable') }
}

// Every input the row renders reports the scope it received.
function ScopeProbe({ context, resourceKey, recordId, actionEndpoint, pivotEndpoint, toolKey, repeaterRow }: FieldInputProps) {
  return (
    <output data-testid="scope">
      {JSON.stringify({ context, resourceKey, recordId, actionEndpoint, pivotEndpoint, toolKey, repeaterRow })}
    </output>
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({ data: [] })
})

afterEach(() => {
  componentRegistry.unregister('field:input:scope-probe')
})

describe('RepeaterFieldInput row scope', () => {
  it('names the row a picker renders in, from the form context of a create form', async () => {
    // A create drawer of orders opened from the detail page of order 7.
    const { container } = renderRepeater('/resources/orders/7', { resourceKey: 'orders', context: 'create' })

    const url = await relatableRequestOf(container, 0)

    expect(url.pathname).toBe('/api/resources/orders/_/relatable/product_id')
    expect(rowOf(url)).toEqual({ repeater: 'lines', repeatable: 'product-line' })
  })

  it('names the row type of the row the picker renders in', async () => {
    const { container } = renderRepeater('/resources/orders/create', { resourceKey: 'orders', context: 'create' })

    const url = await relatableRequestOf(container, 1)

    expect(url.pathname).toBe('/api/resources/orders/_/relatable/product_id')
    expect(rowOf(url)).toEqual({ repeater: 'lines', repeatable: 'service-line' })
  })

  it('keeps the record of an update form', async () => {
    const { container } = renderRepeater('/resources/orders/7/edit', { resourceKey: 'orders', recordId: 7, context: 'update' })

    const url = await relatableRequestOf(container, 0)

    expect(url.pathname).toBe('/api/resources/orders/7/relatable/product_id')
    expect(rowOf(url)).toEqual({ repeater: 'lines', repeatable: 'product-line' })
  })

  it('asks the Action for a row picker of an Action modal', async () => {
    const { container } = renderRepeater('/resources/orders/7', {
      context: 'create',
      actionEndpoint: '/api/resources/orders/actions/add-lines',
    })

    const url = await relatableRequestOf(container, 1)

    expect(url.pathname).toBe('/api/resources/orders/actions/add-lines/relatable/product_id')
    expect(rowOf(url)).toEqual({ repeater: 'lines', repeatable: 'service-line' })
  })

  it('searches the options of a remote Select row field in the form context', async () => {
    const { container } = renderRepeater('/resources/orders/create', { resourceKey: 'orders', context: 'create' })
    apiGetMock.mockResolvedValue({ data: { options: [] } })

    fireEvent.click(container.querySelector('.p-dropdown') as HTMLElement)

    await waitFor(() => expect(requested('/options')).toHaveLength(1))
    const url = requested('/options')[0]
    expect(url.pathname).toBe('/api/resources/orders/fields/unit/options')
    expect(url.searchParams.get('context')).toBe('create')
    expect(rowOf(url)).toEqual({ repeater: 'lines', repeatable: 'product-line' })
  })

  it('hands the form scope and the row to every row input', () => {
    componentRegistry.registerFieldInput('scope-probe', ScopeProbe)
    const probe = { ...base, attribute: 'probe', label: 'Probe', type: 'scope-probe' }

    renderRepeater('/resources/orders/7/edit', {
      resourceKey: 'orders',
      recordId: 7,
      context: 'update',
      actionEndpoint: '/api/resources/orders/actions/add-lines',
      pivotEndpoint: '/api/resources/projects/3/belongs-to-many/members/pivot-fields',
      toolKey: 'importer',
    }, repeaterField([probe]))

    expect(JSON.parse(screen.getByTestId('scope').textContent ?? '')).toEqual({
      context: 'update',
      resourceKey: 'orders',
      recordId: 7,
      actionEndpoint: '/api/resources/orders/actions/add-lines',
      pivotEndpoint: '/api/resources/projects/3/belongs-to-many/members/pivot-fields',
      toolKey: 'importer',
      repeaterRow: { repeater: 'lines', repeatable: 'product-line' },
    })
  })
})
