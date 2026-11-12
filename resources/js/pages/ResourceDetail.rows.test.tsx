import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { FieldDefinition, ResourceSchema } from '@/types'
import { ToastProvider } from '@/contexts/ToastContext'

/*
 * The detail page rows honour `fullWidth()` / `hideLabelOnDetail()`, carry
 * `data-field` / `data-type`, and the kicker follows `Resource::detailKicker()`.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
    },
  }
})

import { ResourceDetailPage } from '@/pages/ResourceDetail'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function field(attribute: string, label: string, type = 'text'): FieldDefinition {
  return {
    attribute, label, type,
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
  } as unknown as FieldDefinition
}

function schemaWith(extra: Record<string, unknown>): ResourceSchema {
  return {
    uriKey: 'employees',
    label: 'Employees',
    singularLabel: 'Employee',
    softDeletes: false,
    fields: [],
    fieldsForDetail: [
      field('name', 'Name'),
      { ...field('bio', 'Bio'), fullWidth: true },
      { ...field('note', 'Note'), hideLabelOnDetail: true },
    ],
    detailSidebar: [field('owner', 'Owner')],
    actions: [],
    messages: {},
    ...extra,
  } as unknown as ResourceSchema
}

function renderDetail(schema: ResourceSchema) {
  apiGetMock.mockImplementation((path: string) => {
    if (path === '/api/resources/employees/schema') return Promise.resolve({ data: schema })
    if (path === '/api/resources/employees/1') {
      return Promise.resolve({ data: { id: 1, _title: 'Ann', name: 'Ann', bio: 'Long', note: 'N', owner: 'Bo' } })
    }
    return Promise.resolve({ data: [] })
  })
  const router = createMemoryRouter(
    [{ path: '/resources/:resource/:id', element: <ResourceDetailPage /> }],
    { initialEntries: ['/resources/employees/1'] },
  )
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGetMock.mockReset()
})

describe('ResourceDetailPage rows', () => {
  it('tags rows and honours fullWidth and hideLabelOnDetail', async () => {
    const { container } = renderDetail(schemaWith({}))
    await screen.findByRole('heading', { name: 'Ann' })

    const row = (attr: string) => container.querySelector(`.martis-detail-row[data-field="${attr}"]`)!
    expect(row('name').getAttribute('data-type')).toBe('text')
    expect(row('name').classList.contains('is-full-width')).toBe(false)
    expect(row('bio').classList.contains('is-full-width')).toBe(true)
    expect(row('note').classList.contains('is-label-hidden')).toBe(true)
    expect(row('owner')).toBeTruthy()
  })
})

describe('ResourceDetailPage kicker', () => {
  it('shows the translated "Details" on both blocks by default', async () => {
    const { container } = renderDetail(schemaWith({}))
    await screen.findByRole('heading', { name: 'Ann' })
    const kickers = Array.from(container.querySelectorAll('.martis-detail-kicker')).map((k) => k.textContent)
    expect(kickers).toHaveLength(2)
    expect(new Set(kickers).size).toBe(1)
    expect(kickers[0]).toBeTruthy()
  })

  it('shows a custom kicker verbatim on both blocks', async () => {
    const { container } = renderDetail(schemaWith({ detailKicker: 'Record sheet' }))
    await screen.findByRole('heading', { name: 'Ann' })
    const kickers = Array.from(container.querySelectorAll('.martis-detail-kicker')).map((k) => k.textContent)
    expect(kickers).toEqual(['Record sheet', 'Record sheet'])
  })

  it('hides both kickers when the resource returns false', async () => {
    const { container } = renderDetail(schemaWith({ detailKicker: false }))
    await screen.findByRole('heading', { name: 'Ann' })
    await waitFor(() => expect(container.querySelectorAll('.martis-detail-row').length).toBeGreaterThan(0))
    expect(container.querySelectorAll('.martis-detail-kicker')).toHaveLength(0)
  })
})
