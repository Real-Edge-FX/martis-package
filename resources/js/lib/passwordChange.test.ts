import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { isOnPage } from '@/lib/passwordChange'

const original = window.location

beforeEach(() => {
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { href: 'http://localhost/martis/password/change', pathname: '/martis/password/change', origin: 'http://localhost' },
  })
})

afterEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: original })
})

describe('isOnPage()', () => {
  it('matches a relative URL with the current path', () => {
    expect(isOnPage('/martis/password/change')).toBe(true)
    expect(isOnPage('/martis/other')).toBe(false)
  })

  it('matches an absolute URL of the same origin', () => {
    expect(isOnPage('http://localhost/martis/password/change')).toBe(true)
    expect(isOnPage('http://localhost/martis/other')).toBe(false)
  })

  it('does not match the same path on another origin', () => {
    expect(isOnPage('https://accounts.example.com/martis/password/change')).toBe(false)
    expect(isOnPage('http://localhost:8080/martis/password/change')).toBe(false)
  })
})
