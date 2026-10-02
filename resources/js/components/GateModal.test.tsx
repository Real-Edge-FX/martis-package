import { describe, expect, it } from 'vitest'
import { act, render } from '@testing-library/react'
import type { GateLock } from '@/types'
import { GateProvider, useGate } from '@/contexts/GateContext'
import { GateModal } from './GateModal'

/*
 * Hardening (F104): the CTA of a gate modal rendered its configured `url` as
 * the `href` of a link as it was, so a `javascript:` URL ran in the panel
 * origin on click. The link now keeps only a web, relative or mailto: / tel:
 * target, and the message, when it is HTML, goes through the shared sanitiser.
 */

function Opener({ lock }: { lock: GateLock }) {
  const gate = useGate()
  return <button type="button" onClick={() => gate.open(lock)}>open</button>
}

function renderGate(lock: GateLock) {
  const view = render(
    <GateProvider>
      <Opener lock={lock} />
      <GateModal />
    </GateProvider>,
  )
  act(() => {
    view.getByRole('button', { name: 'open' }).click()
  })
  return view
}

const lock = (modal: NonNullable<GateLock['modal']>): GateLock => ({ reason: 'plan', modal })

describe('GateModal call to action', () => {
  it.each(['javascript:alert(1)', ' javascript:alert(1)', 'java\tscript:alert(1)', 'data:text/html,<script>alert(1)</script>'])(
    'renders a link without an href for %j',
    (url) => {
      renderGate(lock({ title: 'Locked', cta: { label: 'Upgrade', url } }))

      const link = document.body.querySelector('.martis-modal-foot a') as HTMLAnchorElement
      expect(link.textContent).toBe('Upgrade')
      expect(link.hasAttribute('href')).toBe(false)
    },
  )

  it.each(['https://example.com/pricing', '/pricing', 'mailto:sales@example.com'])('keeps the href %s', (url) => {
    renderGate(lock({ title: 'Locked', cta: { label: 'Upgrade', url } }))

    expect((document.body.querySelector('.martis-modal-foot a') as HTMLAnchorElement).getAttribute('href')).toBe(url)
  })
})

describe('GateModal HTML message', () => {
  it('keeps the markup of a trusted message and strips what would execute', () => {
    renderGate(lock({ messageHtml: true, message: 'Read <a href="https://example.com/plans">the plans</a> <b>now</b><img src=x onerror="window.__pwned = 1"><script>window.__pwned = 2</script>' }))

    const body = document.body.querySelector('.martis-modal-body p') as HTMLElement
    expect(body.querySelector('a')?.getAttribute('href')).toBe('https://example.com/plans')
    expect(body.querySelector('b')?.textContent).toBe('now')
    expect(body.querySelector('script')).toBeNull()
    expect(body.innerHTML.toLowerCase()).not.toContain('onerror')
    expect((window as unknown as Record<string, unknown>).__pwned).toBeUndefined()
  })

  it('renders a plain message as text', () => {
    renderGate(lock({ message: '<b>not bold</b>' }))

    const body = document.body.querySelector('.martis-modal-body p') as HTMLElement
    expect(body.querySelector('b')).toBeNull()
    expect(body.textContent).toBe('<b>not bold</b>')
  })
})
