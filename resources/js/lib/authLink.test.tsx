import { StrictMode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { parseAuthLinkFragment, useAuthLinkParams } from './authLink'

describe('parseAuthLinkFragment', () => {
  it('reads the token and email of an emailed link fragment', () => {
    expect(parseAuthLinkFragment('#token=abc%2B1&email=ada%40example.com')).toEqual({
      token: 'abc+1',
      email: 'ada@example.com',
    })
  })

  it('answers empty strings for what the fragment lacks', () => {
    expect(parseAuthLinkFragment('')).toEqual({ token: '', email: '' })
    expect(parseAuthLinkFragment('#token=abc')).toEqual({ token: 'abc', email: '' })
  })
})

describe('useAuthLinkParams', () => {
  const original = window.location
  let replaceState: ReturnType<typeof vi.spyOn>

  function Probe() {
    const { token, email } = useAuthLinkParams()
    return <p data-testid="link">{`${token}|${email}`}</p>
  }

  function stubLocation(hash: string) {
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: {
        href: 'http://localhost/martis/reset-password?x=1' + hash,
        pathname: '/martis/reset-password',
        origin: 'http://localhost',
        search: '?x=1',
        hash,
      },
    })
  }

  beforeEach(() => {
    // jsdom refuses a history URL that is not its own origin: the stubbed location is not.
    replaceState = vi.spyOn(window.history, 'replaceState').mockImplementation(() => {})
  })

  afterEach(() => {
    replaceState.mockRestore()
    Object.defineProperty(window, 'location', { configurable: true, value: original })
  })

  it('reads the fragment once, then drops it from the address bar and keeps the rest', () => {
    stubLocation('#token=tok-1&email=ada%40example.com')
    render(
      <MemoryRouter initialEntries={['/reset-password#token=tok-1&email=ada%40example.com']}>
        <Probe />
      </MemoryRouter>,
    )

    expect(screen.getByTestId('link').textContent).toBe('tok-1|ada@example.com')
    expect(replaceState).toHaveBeenCalledTimes(1)
    expect(replaceState.mock.calls[0][2]).toBe('/martis/reset-password?x=1')
  })

  it('keeps the router history state when it drops the fragment', () => {
    stubLocation('#token=tok-1')
    const state = { usr: null, key: 'k1', idx: 3 }
    const stateSpy = vi.spyOn(window.history, 'state', 'get').mockReturnValue(state)
    render(
      <MemoryRouter initialEntries={['/reset-password#token=tok-1']}>
        <Probe />
      </MemoryRouter>,
    )

    expect(replaceState.mock.calls[0][0]).toBe(state)
    stateSpy.mockRestore()
  })

  it('reads the link once under StrictMode', () => {
    stubLocation('#token=tok-1&email=ada%40example.com')
    render(
      <StrictMode>
        <MemoryRouter initialEntries={['/reset-password#token=tok-1&email=ada%40example.com']}>
          <Probe />
        </MemoryRouter>
      </StrictMode>,
    )

    expect(screen.getByTestId('link').textContent).toBe('tok-1|ada@example.com')
  })

  it('leaves the address bar alone when there is no fragment', () => {
    stubLocation('')
    render(
      <MemoryRouter initialEntries={['/reset-password']}>
        <Probe />
      </MemoryRouter>,
    )

    expect(screen.getByTestId('link').textContent).toBe('|')
    expect(replaceState).not.toHaveBeenCalled()
  })
})
