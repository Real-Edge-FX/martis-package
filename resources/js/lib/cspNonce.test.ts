import { afterEach, describe, expect, it } from 'vitest'
import { readCspNonce } from './cspNonce'

/*
 * F101: the shell publishes the request's CSP nonce in a meta tag so the SPA
 * can stamp it on the style elements it injects at run time.
 */

afterEach(() => {
  document.head.querySelectorAll('meta[name="csp-nonce"]').forEach((meta) => meta.remove())
})

function publish(content: string): void {
  const meta = document.createElement('meta')
  meta.setAttribute('name', 'csp-nonce')
  meta.setAttribute('content', content)
  document.head.appendChild(meta)
}

describe('readCspNonce', () => {
  it('reads the nonce the shell published', () => {
    publish('abc123nonce')

    expect(readCspNonce()).toBe('abc123nonce')
  })

  it('returns null when the shell published none', () => {
    expect(readCspNonce()).toBeNull()
  })

  it('returns null for an empty nonce', () => {
    publish('')

    expect(readCspNonce()).toBeNull()
  })
})
