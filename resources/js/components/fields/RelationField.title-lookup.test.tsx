import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { FieldDefinition } from '@/types'
import { LOCKED_EVENT } from '@/lib/lockEvent'

/*
 * A BelongsTo / MorphTo that holds a bare id (a field default) looks the
 * record's title up so the trigger shows it instead of `#id`. The lookup is
 * the page's own: one cached request per record however many inputs hold it
 * (a Repeater's rows), and a refusal, a soft-locked resource included, never
 * opens the gate modal: the `#id` fallback stays.
 */

vi.mock('@/lib/config', () => ({ config: { auth: {} }, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))

import { BelongsToFieldInput } from './BelongsToField'
import { MorphToFieldInput } from './MorphToField'

const LOCK = { reason: 'plan:pro', modal: { title: 'Pro feature', message: 'Upgrade to unlock.' } }

function field(type: 'belongs_to' | 'morph_to'): FieldDefinition {
  return {
    attribute: 'author', label: 'Author', type, relatedResource: 'users', types: [{ key: 'users', label: 'Users' }],
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: false, showOnDetail: true, showOnForms: true, rules: [],
  } as unknown as FieldDefinition
}

let fetchMock: ReturnType<typeof vi.fn>

function answer(status: number, body: unknown) {
  fetchMock = vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }))
  vi.stubGlobal('fetch', fetchMock)
}

function lookups(): string[] {
  return fetchMock.mock.calls.map((c) => String(c[0])).filter((u) => /\/api\/resources\/users\/5$/.test(u))
}

function mount(inputs: Array<'belongs_to' | 'morph_to'>) {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter>
        {inputs.map((type, i) =>
          type === 'belongs_to'
            ? <BelongsToFieldInput key={i} field={field(type)} value={5} onChange={() => {}} resourceKey="posts" />
            : <MorphToFieldInput key={i} field={field(type)} value={{ resourceType: 'users', id: 5 }} onChange={() => {}} resourceKey="posts" />,
        )}
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const labels = () => Array.from(document.querySelectorAll('.martis-belongs-to-trigger-label')).map((el) => el.textContent)

let locked: unknown[]
const onLocked = (event: Event) => locked.push((event as CustomEvent).detail)

beforeEach(() => {
  locked = []
  window.addEventListener(LOCKED_EVENT, onLocked)
})

afterEach(() => {
  window.removeEventListener(LOCKED_EVENT, onLocked)
  vi.unstubAllGlobals()
})

describe('the title lookup of a bare id', () => {
  it.each(['belongs_to', 'morph_to'] as const)('shows the title it finds (%s)', async (type) => {
    answer(200, { data: { id: 5, _title: 'Ana' } })
    mount([type])

    await waitFor(() => expect(labels()).toEqual(['Ana']))
  })

  it('asks once for a record several inputs hold', async () => {
    answer(200, { data: { id: 5, _title: 'Ana' } })
    mount(['belongs_to', 'belongs_to', 'morph_to'])

    await waitFor(() => expect(labels()).toEqual(['Ana', 'Ana', 'Ana']))
    expect(lookups()).toHaveLength(1)
  })

  it.each(['belongs_to', 'morph_to'] as const)('keeps #id and opens no gate modal when the answer is a lock (%s)', async (type) => {
    answer(403, { message: 'Locked.', errors: [], locked: true, lock: LOCK })
    mount([type])

    await waitFor(() => expect(lookups()).toHaveLength(1))
    await new Promise((resolve) => setTimeout(resolve, 20))

    expect(locked).toEqual([])
    expect(labels()).toEqual(['#5'])
  })

  it('keeps #id on a plain refusal and does not retry', async () => {
    answer(403, { message: 'This action is unauthorized.', errors: [] })
    mount(['belongs_to'])

    await waitFor(() => expect(lookups()).toHaveLength(1))
    await new Promise((resolve) => setTimeout(resolve, 20))

    expect(labels()).toEqual(['#5'])
    expect(lookups()).toHaveLength(1)
  })
})
