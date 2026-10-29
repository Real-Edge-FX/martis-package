import { describe, it, expect } from 'vitest'
import { renderHook } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { useRefreshRecord } from './useRefreshRecord'

describe('useRefreshRecord', () => {
  it('invalidates the record detail (and its edit form) and the resource lists, nothing else', () => {
    const client = new QueryClient()
    const keys: unknown[][] = [
      ['resource', 'posts', '5'],
      ['resource', 'posts', '5', 'update'],
      ['resources', 'posts', 1],
      ['resource', 'posts', '6'],
      ['resource', 'users', '5'],
      ['resources', 'users', 1],
    ]
    keys.forEach((k) => client.setQueryData(k, 1))
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>
    const { result } = renderHook(() => useRefreshRecord(), { wrapper })

    result.current('posts', 5)

    const stale = (k: unknown[]) => client.getQueryState(k)?.isInvalidated
    expect(stale(keys[0])).toBe(true)
    expect(stale(keys[1])).toBe(true)
    expect(stale(keys[2])).toBe(true)
    expect(stale(keys[3])).toBe(false)
    expect(stale(keys[4])).toBe(false)
    expect(stale(keys[5])).toBe(false)
  })
})
