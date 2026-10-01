import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'

// AuthProvider and PreferencesProvider can start from given values, without
// a request, for a tree mounted outside the shell (the test kit, v2.3.0).

const { get, put, del } = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), del: vi.fn() }))

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: { get: (...a: unknown[]) => get(...a), put: (...a: unknown[]) => put(...a), delete: (...a: unknown[]) => del(...a), post: vi.fn() },
}))

import { AuthProvider, useAuth } from './AuthContext'
import { PreferencesProvider, usePreferences } from './PreferencesContext'

const ada = { id: 7, name: 'Ada', email: 'ada@example.com' }

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  del.mockReset()
  localStorage.clear()
})

describe('providers with initial values', () => {
  it('AuthProvider starts from initialUser without asking the server', async () => {
    const { result } = renderHook(() => useAuth(), {
      wrapper: ({ children }: { children: ReactNode }) => <AuthProvider initialUser={ada}>{children}</AuthProvider>,
    })

    expect(result.current.isLoading).toBe(false)
    expect(result.current.user?.id).toBe(7)
    await act(async () => {})
    expect(get).not.toHaveBeenCalled()
  })

  it('AuthProvider renders a guest with initialUser={null}', () => {
    const { result } = renderHook(() => useAuth(), {
      wrapper: ({ children }: { children: ReactNode }) => <AuthProvider initialUser={null}>{children}</AuthProvider>,
    })

    expect(result.current.user).toBeNull()
    expect(get).not.toHaveBeenCalled()
  })

  it('PreferencesProvider with syncWithServer={false} keeps preferences in memory', async () => {
    const { result } = renderHook(() => usePreferences(), {
      wrapper: ({ children }: { children: ReactNode }) => (
        <AuthProvider initialUser={ada}>
          <PreferencesProvider initialPreferences={{ theme: 'light' }} syncWithServer={false}>{children}</PreferencesProvider>
        </AuthProvider>
      ),
    })

    expect(result.current.prefs.theme).toBe('light')
    await act(() => result.current.update({ density: 'dense' }))
    expect(result.current.prefs.density).toBe('dense')
    await act(() => result.current.reset())
    expect(result.current.prefs.theme).toBe('light')
    expect(result.current.prefs.density).toBe('comfortable')

    expect(get).not.toHaveBeenCalled()
    expect(put).not.toHaveBeenCalled()
    expect(del).not.toHaveBeenCalled()
    expect(localStorage.getItem('martis-preferences')).toBeNull()
  })
})
