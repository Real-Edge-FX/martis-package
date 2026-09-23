import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

const { useRemoteSelectOptions, remoteOptionsEndpoint, REMOTE_SELECT_DEBOUNCE_MS } = await import('./useRemoteSelectOptions')

function deferred<T>() {
  let resolve!: (v: T) => void
  let reject!: (e: unknown) => void
  const promise = new Promise<T>((res, rej) => { resolve = res; reject = rej })
  return { promise, resolve, reject }
}

beforeEach(() => {
  vi.useFakeTimers()
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({ data: { options: [{ label: 'GPT-4o', value: 'gpt-4o' }] } })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('remoteOptionsEndpoint', () => {
  it('routes at the Tool when a toolKey is set, even with a resourceKey', () => {
    expect(remoteOptionsEndpoint('model', { toolKey: 'settings', resourceKey: 'clients' }))
      .toBe('/api/tools/settings/fields/model/options')
  })

  it('routes at the Resource with the form context', () => {
    expect(remoteOptionsEndpoint('model', { resourceKey: 'clients', context: 'update' }))
      .toBe('/api/resources/clients/fields/model/options?context=update')
    expect(remoteOptionsEndpoint('model', { resourceKey: 'clients' }))
      .toBe('/api/resources/clients/fields/model/options?context=create')
  })

  it('adds the record id in the update context so the server can bind the record before gating', () => {
    expect(remoteOptionsEndpoint('model', { resourceKey: 'clients', context: 'update', recordId: 42 }))
      .toBe('/api/resources/clients/fields/model/options?context=update&id=42')
    // Not needed for create, and never for a Tool.
    expect(remoteOptionsEndpoint('model', { resourceKey: 'clients', context: 'create', recordId: 42 }))
      .toBe('/api/resources/clients/fields/model/options?context=create')
    expect(remoteOptionsEndpoint('model', { toolKey: 't', context: 'update', recordId: 42 }))
      .toBe('/api/tools/t/fields/model/options')
  })

  it('names the Repeater row of a row field, for the Resource and the Tool endpoints', () => {
    const repeaterRow = { repeater: 'lines', repeatable: 'product-line' }

    expect(remoteOptionsEndpoint('unit', { resourceKey: 'orders', context: 'update', recordId: 7, repeaterRow }))
      .toBe('/api/resources/orders/fields/unit/options?context=update&id=7&repeater=lines&repeatable=product-line')
    expect(remoteOptionsEndpoint('unit', { toolKey: 'importer', repeaterRow }))
      .toBe('/api/tools/importer/fields/unit/options?repeater=lines&repeatable=product-line')
  })

  it('returns null without a scope', () => {
    expect(remoteOptionsEndpoint('model', {})).toBeNull()
  })

  it('URL-encodes the keys and the attribute', () => {
    expect(remoteOptionsEndpoint('a b', { toolKey: 'x/y' })).toBe('/api/tools/x%2Fy/fields/a%20b/options')
  })
})

describe('useRemoteSelectOptions', () => {
  it('does nothing while closed or without an endpoint', () => {
    renderHook(() => useRemoteSelectOptions({ endpoint: '/api/tools/t/fields/m/options', open: false, term: '' }))
    renderHook(() => useRemoteSelectOptions({ endpoint: null, open: true, term: '' }))
    act(() => { vi.runAllTimers() })
    expect(apiGetMock).not.toHaveBeenCalled()
  })

  it('fetches at once with an empty term when the panel opens and maps the options', async () => {
    const { result } = renderHook(() => useRemoteSelectOptions({ endpoint: '/api/tools/t/fields/m/options', open: true, term: '' }))
    await act(async () => { vi.runAllTimers() })

    expect(apiGetMock).toHaveBeenCalledTimes(1)
    expect(apiGetMock.mock.calls[0][0]).toBe('/api/tools/t/fields/m/options?search=')
    expect(result.current.options).toEqual([{ label: 'GPT-4o', value: 'gpt-4o' }])
    expect(result.current.loading).toBe(false)
    expect(result.current.error).toBe(false)
  })

  it('appends the term with & when the endpoint already has a query string', async () => {
    renderHook(() => useRemoteSelectOptions({ endpoint: '/api/resources/r/fields/m/options?context=update', open: true, term: '' }))
    await act(async () => { vi.runAllTimers() })
    expect(apiGetMock.mock.calls[0][0]).toBe('/api/resources/r/fields/m/options?context=update&search=')
  })

  it('debounces typing and only requests the latest term, trimmed and encoded', async () => {
    const { rerender } = renderHook(
      ({ term }) => useRemoteSelectOptions({ endpoint: '/api/tools/t/fields/m/options', open: true, term }),
      { initialProps: { term: '' } },
    )
    await act(async () => { vi.runAllTimers() })
    apiGetMock.mockClear()

    rerender({ term: 'g' })
    rerender({ term: 'gp' })
    rerender({ term: ' gpt 4 ' })
    act(() => { vi.advanceTimersByTime(REMOTE_SELECT_DEBOUNCE_MS - 1) })
    expect(apiGetMock).not.toHaveBeenCalled()
    await act(async () => { vi.advanceTimersByTime(1) })

    expect(apiGetMock).toHaveBeenCalledTimes(1)
    expect(apiGetMock.mock.calls[0][0]).toBe('/api/tools/t/fields/m/options?search=gpt%204')
  })

  it('aborts the in-flight request when a newer one starts and ignores its result', async () => {
    const first = deferred<unknown>()
    apiGetMock.mockReturnValueOnce(first.promise)
    const { result, rerender } = renderHook(
      ({ term }) => useRemoteSelectOptions({ endpoint: '/api/tools/t/fields/m/options', open: true, term }),
      { initialProps: { term: '' } },
    )
    await act(async () => { vi.runAllTimers() })
    const firstSignal = apiGetMock.mock.calls[0][1] as AbortSignal

    rerender({ term: 'gpt' })
    await act(async () => { vi.runAllTimers() })
    expect(firstSignal.aborted).toBe(true)

    await act(async () => { first.resolve({ data: { options: [{ label: 'stale', value: 'stale' }] } }) })
    expect(result.current.options).toEqual([{ label: 'GPT-4o', value: 'gpt-4o' }])
  })

  it('flags an error and empties the list when the request fails', async () => {
    apiGetMock.mockRejectedValueOnce(new Error('boom'))
    const { result } = renderHook(() => useRemoteSelectOptions({ endpoint: '/api/tools/t/fields/m/options', open: true, term: '' }))
    await act(async () => { vi.runAllTimers() })

    expect(result.current.error).toBe(true)
    expect(result.current.options).toEqual([])
    expect(result.current.loading).toBe(false)
  })

  it('resets the list when the panel closes so the next open starts from the initial options', async () => {
    const { result, rerender } = renderHook(
      ({ open }) => useRemoteSelectOptions({ endpoint: '/api/tools/t/fields/m/options', open, term: '' }),
      { initialProps: { open: true } },
    )
    await act(async () => { vi.runAllTimers() })
    expect(result.current.options).not.toBeNull()

    rerender({ open: false })
    expect(result.current.options).toBeNull()
  })
})
