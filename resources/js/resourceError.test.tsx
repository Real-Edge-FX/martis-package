import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { ApiError } from '@/lib/api'
import { ResourceErrorPage } from '@/pages/ResourceError'

// -----------------------------------------------------------------------------
// ResourceErrorPage — triages query errors by HTTP status so a 5xx is no
// longer hidden behind the generic 404 "Resource not found" page.
// -----------------------------------------------------------------------------

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (_key: string, options?: { defaultValue?: string }) => options?.defaultValue ?? _key,
  }),
}))

vi.mock('@/lib/config', () => ({
  config: { docsUrl: null },
}))

function renderPage(error: unknown) {
  return render(
    <MemoryRouter>
      <ResourceErrorPage error={error} />
    </MemoryRouter>,
  )
}

describe('ResourceErrorPage', () => {
  it('renders the 404 page when the error is an ApiError with status 404', () => {
    renderPage(new ApiError(404, 'Not Found'))
    expect(screen.getByText('Resource not found')).toBeTruthy()
    expect(screen.getByText('404')).toBeTruthy()
  })

  it('renders the 403 page when the error is an ApiError with status 403', () => {
    renderPage(new ApiError(403, 'Forbidden'))
    expect(screen.getByText('Access denied')).toBeTruthy()
    expect(screen.getByText('403')).toBeTruthy()
  })

  it('renders the server-error page for any 5xx status', () => {
    renderPage(new ApiError(500, 'Server Error'))
    expect(screen.getByText('Server error')).toBeTruthy()
    // The status code is the watermark behind the icon — it must surface so
    // operators see the real cause rather than the generic 404 fallback.
    expect(screen.getByText('500')).toBeTruthy()
  })

  it('renders the server-error page for 502/503/504 too', () => {
    for (const status of [502, 503, 504]) {
      const { unmount } = renderPage(new ApiError(status, `${status} upstream`))
      expect(screen.getByText('Server error')).toBeTruthy()
      expect(screen.getByText(String(status))).toBeTruthy()
      unmount()
    }
  })

  it('renders the network-error page for non-ApiError throws', () => {
    renderPage(new Error('TypeError: Failed to fetch'))
    expect(screen.getByText('Cannot reach the server')).toBeTruthy()
    // A dash sits where the status code would be — the request never landed
    // a response so there is no number to show.
    expect(screen.getByText('—')).toBeTruthy()
  })

  it('falls back to the 404 page for other 4xx statuses (422, 410, 405, ...)', () => {
    for (const status of [400, 405, 410, 422]) {
      const { unmount } = renderPage(new ApiError(status, 'something went wrong'))
      expect(screen.getByText('Resource not found')).toBeTruthy()
      expect(screen.getByText(String(status))).toBeTruthy()
      unmount()
    }
  })

  it('does NOT leak the response body or stack trace into the rendered DOM', () => {
    const message = 'SQLSTATE[42S02]: Base table or view not found: users.foobar'
    renderPage(new ApiError(500, message))
    // The detailed message must stay out of the production UI — operators
    // read storage/logs/laravel.log for the full trace.
    expect(screen.queryByText(message)).toBeNull()
    expect(screen.queryByText(/SQLSTATE/)).toBeNull()
  })
})
