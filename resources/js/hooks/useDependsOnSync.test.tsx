import { it, expect, vi, beforeEach } from 'vitest'
import { renderHook, act, waitFor } from '@testing-library/react'
import type { FieldDefinition } from '@/types'

const apiPostMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, post: (...args: unknown[]) => apiPostMock(...args) } }
})

const { useDependsOnSync } = await import('./useDependsOnSync')

const fields: FieldDefinition[] = [
  { type: 'text', attribute: 'plan', label: 'Plan' } as unknown as FieldDefinition,
  { type: 'number', attribute: 'price', label: 'Price', dependsOn: { fields: ['plan'] } } as unknown as FieldDefinition,
]

beforeEach(() => {
  apiPostMock.mockReset()
  apiPostMock.mockResolvedValue({ data: { attribute: 'price' } })
})

it('posts the record id with an update-context sync so the server binds the record before gating', async () => {
  const { rerender } = renderHook(
    ({ formValues }) => useDependsOnSync({ resource: 'projects', context: 'update', recordId: 42, fields, formValues }),
    { initialProps: { formValues: { plan: 'free' } as Record<string, unknown> } },
  )

  act(() => { rerender({ formValues: { plan: 'paid' } }) })

  await waitFor(() => expect(apiPostMock).toHaveBeenCalled())
  const [url, body] = apiPostMock.mock.calls[0] as [string, Record<string, unknown>]
  expect(url).toBe('/api/resources/projects/sync-field')
  expect(body).toMatchObject({ field: 'price', context: 'update', id: 42, formData: { plan: 'paid' } })
})

it('omits the id when the form is not bound to a record', async () => {
  const { rerender } = renderHook(
    ({ formValues }) => useDependsOnSync({ resource: 'projects', context: 'create', fields, formValues }),
    { initialProps: { formValues: { plan: 'free' } as Record<string, unknown> } },
  )

  act(() => { rerender({ formValues: { plan: 'paid' } }) })

  await waitFor(() => expect(apiPostMock).toHaveBeenCalled())
  const body = apiPostMock.mock.calls[0][1] as Record<string, unknown>
  expect('id' in body).toBe(false)
})
