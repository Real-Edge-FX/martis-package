import { expect, vi } from 'vitest'
import { render } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import type { ReactNode } from 'react'
import { api } from '@/lib/api'
import type { FieldDefinition, FieldType } from '@/types'

/**
 * Helpers for tests that check which record a relationship panel reads its
 * related records from. The calling test file mocks `@/lib/api` (`api.get` as
 * a `vi.fn()`) and calls `answerRelationRequests()` before each test.
 */

/** A relationship field as the schema serialises it, with every affordance on. */
export function relationField(
  type: string,
  relationship: string,
  relatedResource: string,
  metaKey: string,
): FieldDefinition {
  return {
    attribute: relationship,
    label: relationship,
    type: type as FieldType,
    nullable: true,
    readonly: false,
    required: false,
    sortable: false,
    searchable: false,
    showOnIndex: false,
    showOnDetail: true,
    showOnForms: true,
    rules: [],
    relationship,
    relatedResource,
    [metaKey]: {
      perPage: 10,
      perPageOptions: [10],
      canCreate: true,
      canUpdate: true,
      canDelete: true,
      canAttach: true,
      canDetach: true,
    },
  }
}

/** Answer every GET a relationship panel makes with an empty result. */
export function answerRelationRequests(): void {
  vi.mocked(api.get).mockReset()
  vi.mocked(api.get).mockImplementation(((url: string) => {
    if (url.endsWith('/schema')) {
      return Promise.resolve({
        data: { fieldsForIndex: [], fieldsForDetail: [], singularLabel: 'Record', softDeletes: false },
      })
    }
    if (url.includes('/actions?')) return Promise.resolve({ data: { actions: [] } })
    if (/\/(has-one|morph-one)\//.test(url)) return Promise.resolve({ data: null })
    return Promise.resolve({
      data: [],
      meta: { current_page: 1, from: null, last_page: 1, per_page: 10, to: null, total: 0 },
      links: { first: null, last: null, prev: null, next: null },
    })
  }) as unknown as typeof api.get)
}

/** Every URL requested through `api.get`, in order. */
export function requestedUrls(): string[] {
  return vi.mocked(api.get).mock.calls.map(([url]) => String(url))
}

/** Matches a string that starts with `prefix`. */
export function startingWith(prefix: string): unknown {
  return expect.stringMatching(new RegExp(`^${prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`))
}

function LocationProbe() {
  const location = useLocation()
  return <output data-testid="location">{location.pathname + location.search}</output>
}

/**
 * Render `ui` as the page at `path` (matched by `routePattern`). The page URL
 * is also written to `window.location`, so a panel that parses the pathname
 * sees the same page as one that reads the route params. A create page
 * renders the location, so a test can read where a Create button went.
 */
export function renderOnPage(path: string, routePattern: string, ui: ReactNode) {
  window.history.replaceState(null, '', `/martis${path}`)
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path={routePattern} element={ui} />
          <Route path="/resources/:resource/create" element={<LocationProbe />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
