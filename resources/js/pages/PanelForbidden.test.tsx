import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

const signOut = vi.fn(() => Promise.resolve())
vi.mock('@/lib/signOut', () => ({ signOut: () => signOut() }))

const post = vi.fn((_url: string, _body?: unknown) => Promise.resolve({ active: false }))
vi.mock('@/lib/api', () => ({ api: { post: (url: string, body?: unknown) => post(url, body) } }))

import { config } from '@/lib/config'
import { PanelForbiddenPage, stopImpersonating } from './PanelForbidden'

function setImpersonating(value: boolean | undefined): void {
  ;(config as { panelForbiddenImpersonating?: boolean }).panelForbiddenImpersonating = value
}

describe('PanelForbiddenPage', () => {
  beforeEach(() => {
    signOut.mockClear()
    post.mockClear()
  })
  afterEach(() => setImpersonating(undefined))

  it('explains the refusal and signs the user out', () => {
    render(<MemoryRouter><PanelForbiddenPage /></MemoryRouter>)

    expect(screen.getByText('No access to this panel')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: /Sign out/ }))

    expect(signOut).toHaveBeenCalledTimes(1)
    expect(post).not.toHaveBeenCalled()
  })

  it('offers no documentation link', () => {
    render(<MemoryRouter><PanelForbiddenPage /></MemoryRouter>)

    expect(screen.queryByRole('link')).toBeNull()
  })

  it('stops the impersonation instead of signing out when the refused user is impersonated', async () => {
    setImpersonating(true)
    // Left pending: the reload that follows is covered by stopImpersonating() below.
    post.mockImplementationOnce(() => new Promise(() => {}))
    render(<MemoryRouter><PanelForbiddenPage /></MemoryRouter>)

    expect(screen.queryByRole('button', { name: /Sign out/ })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: /Stop impersonating/ }))

    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/impersonation/stop', {}))
    expect(signOut).not.toHaveBeenCalled()
  })
})

describe('stopImpersonating()', () => {
  beforeEach(() => {
    signOut.mockClear()
    post.mockClear()
  })

  it('reloads once the operator is restored', async () => {
    const reload = vi.fn()

    await stopImpersonating(reload)

    expect(post).toHaveBeenCalledWith('/api/impersonation/stop', {})
    expect(reload).toHaveBeenCalledTimes(1)
    expect(signOut).not.toHaveBeenCalled()
  })

  it('still ends the session through the sign-out when stopping fails', async () => {
    post.mockImplementationOnce(() => Promise.reject(new Error('403')))
    const reload = vi.fn()

    await stopImpersonating(reload)

    expect(signOut).toHaveBeenCalledTimes(1)
    expect(reload).not.toHaveBeenCalled()
  })
})
