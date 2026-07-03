import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import type { FieldDefinition } from '@/types'

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

import { useToolFields } from './useToolFields'

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

const titleField = {
  attribute: 'title',
  label: 'Title',
  type: 'text',
} as unknown as FieldDefinition

const slugField = {
  attribute: 'slug',
  label: 'Slug',
  type: 'slug',
} as unknown as FieldDefinition

describe('useToolFields', () => {
  beforeEach(() => {
    apiGetMock.mockReset()
  })

  it('fetches and returns the Tool field definitions', async () => {
    apiGetMock.mockResolvedValue({ fields: [titleField, slugField] })

    const { result } = renderHook(() => useToolFields('projects'), { wrapper })

    await waitFor(() => expect(result.current.fields).toEqual([titleField, slugField]))

    expect(apiGetMock).toHaveBeenCalledWith('/api/tools/projects/fields')
  })

  it('does not call api.get when toolKey is empty', () => {
    renderHook(() => useToolFields(''), { wrapper })

    expect(apiGetMock).not.toHaveBeenCalled()
  })
})
