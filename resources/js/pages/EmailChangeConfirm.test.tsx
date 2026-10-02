import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router'

const { post, addToast } = vi.hoisted(() => ({ post: vi.fn(), addToast: vi.fn() }))

vi.mock('@/lib/api', () => ({ api: { post: (...args: unknown[]) => post(...args) } }))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast }) }))
vi.mock('@/lib/config', () => ({ config: {}, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))
vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { EmailChangeConfirmPage } from './EmailChangeConfirm'

const original = window.location
let location: { href: string; pathname: string; origin: string; search: string }

function Where() {
  const here = useLocation()
  return <p data-testid="where">{here.pathname + here.search}</p>
}

function renderAt(search: string) {
  return render(
    <MemoryRouter initialEntries={[`/profile/email/confirm/7${search}`]}>
      <Routes>
        <Route path="/profile/email/confirm/:id" element={<EmailChangeConfirmPage />} />
        <Route path="/login" element={<Where />} />
      </Routes>
    </MemoryRouter>,
  )
}

const QUERY = '?from=abc&to=new%40example.com&expires=1900000000&signature=sig123'

beforeEach(() => {
  post.mockReset()
  addToast.mockReset()
  location = {
    href: 'http://localhost/martis/profile/email/confirm/7' + QUERY,
    pathname: '/martis/profile/email/confirm/7',
    origin: 'http://localhost',
    search: QUERY,
  }
  Object.defineProperty(window, 'location', { configurable: true, value: location })
})

afterEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: original })
})

describe('EmailChangeConfirmPage', () => {
  it('names the new address and sends nothing until the button is clicked', () => {
    renderAt(QUERY)

    expect(screen.getByText('Your account will use new@example.com from now on.')).toBeTruthy()
    expect(post).not.toHaveBeenCalled()
  })

  it('POSTs to the same signed URL, query string untouched, then follows the answered redirect', async () => {
    post.mockResolvedValue({ outcome: 'changed', redirect: '/martis/login?email_change=changed' })
    renderAt(QUERY)

    fireEvent.click(screen.getByRole('button', { name: /confirm email address/i }))

    await waitFor(() => expect(location.href).toBe('/martis/login?email_change=changed'))
    expect(post).toHaveBeenCalledTimes(1)
    expect(post).toHaveBeenCalledWith(`/profile/email/confirm/7${QUERY}`)
  })

  it('toasts the error and stays on the page when the request fails', async () => {
    post.mockRejectedValue(new Error('Server exploded'))
    renderAt(QUERY)

    fireEvent.click(screen.getByRole('button', { name: /confirm email address/i }))

    await waitFor(() => expect(addToast).toHaveBeenCalledWith('error', 'Server exploded'))
    expect(location.href).toContain('/profile/email/confirm/7')
  })

  it('goes to the login page as invalid, without a request, when the link carries no signature or address', () => {
    renderAt('?from=abc&to=new%40example.com')

    expect(screen.getByTestId('where').textContent).toBe('/login?email_change=invalid')
    expect(post).not.toHaveBeenCalled()
  })
})
