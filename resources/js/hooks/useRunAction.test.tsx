import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, act, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { ToastProvider } from '@/contexts/ToastContext'
import { martisEventBus } from '@/lib/eventBus'

const apiPostMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, post: (...a: unknown[]) => apiPostMock(...a) } }
})

import { ApiError } from '@/lib/api'
import { useRunAction } from './useRunAction'

function wrapper(client = new QueryClient()) {
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>
      <ToastProvider>
        <MemoryRouter>{children}</MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>
  )
}

beforeEach(() => {
  apiPostMock.mockReset()
})

describe('useRunAction', () => {
  it('posts to the action endpoint, emits the event and returns the response', async () => {
    const response = { type: 'message', data: { message: 'Done.' } }
    apiPostMock.mockResolvedValue({ data: response })
    const emitted = vi.fn()
    martisEventBus.on('martis:action-executed', emitted)
    const { result } = renderHook(() => useRunAction(), { wrapper: wrapper() })

    let outcome: unknown
    await act(async () => {
      outcome = await result.current({ resource: 'posts', action: 'publish', id: 5, fields: { note: 'x' }, handleResponse: false })
    })
    martisEventBus.off('martis:action-executed', emitted)

    expect(apiPostMock).toHaveBeenCalledWith('/api/resources/posts/actions/publish', { resources: [5], fields: { note: 'x' }, dryRun: false })
    expect(outcome).toEqual({ ok: true, response })
    expect(emitted).toHaveBeenCalledWith({ resourceKey: 'posts', action: 'publish', ids: ['5'] })
  })

  it('maps 422 errors per field with the fields. prefix removed', async () => {
    apiPostMock.mockRejectedValue(
      new ApiError(422, 'The given data was invalid.', [
        { field: 'fields.note', message: 'Note is required.', code: 'required' },
        { field: 'fields.note', message: 'second', code: 'x' },
      ]),
    )
    const { result } = renderHook(() => useRunAction(), { wrapper: wrapper() })

    let outcome: unknown
    await act(async () => {
      outcome = await result.current({ resource: 'posts', action: 'publish', ids: [1, 2] })
    })

    expect(outcome).toEqual({ ok: false, message: 'The given data was invalid.', errors: { note: 'Note is required.' } })
  })

  it('refreshes the record after a handled answer', async () => {
    apiPostMock.mockResolvedValue({ data: { type: 'message', data: { message: 'Done.' } } })
    const client = new QueryClient()
    const spy = vi.spyOn(client, 'invalidateQueries')
    const { result } = renderHook(() => useRunAction(), { wrapper: wrapper(client) })
    await act(async () => {
      await result.current({ resource: 'posts', action: 'publish', id: 5 })
    })
    await waitFor(() => expect(spy).toHaveBeenCalled())
  })

  it('still reports success when handling the answer of a run that succeeded throws', async () => {
    const response = { type: 'message', data: { message: 'Done.' } }
    apiPostMock.mockResolvedValue({ data: response })
    const client = new QueryClient()
    vi.spyOn(client, 'invalidateQueries').mockImplementation(() => { throw new Error('refresh broke') })
    const logged = vi.spyOn(console, 'error').mockImplementation(() => {})
    const { result } = renderHook(() => useRunAction(), { wrapper: wrapper(client) })

    let outcome: unknown
    await act(async () => {
      outcome = await result.current({ resource: 'posts', action: 'publish', id: 5 })
    })

    expect(outcome).toEqual({ ok: true, response })
    expect(logged).toHaveBeenCalled()
    logged.mockRestore()
  })
})
