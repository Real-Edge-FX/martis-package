import { describe, it, expect } from 'vitest'
import { useState } from 'react'
import { render, act } from '@testing-library/react'
import { getOpenLayerCount, hasOpenLayer, useEscapeLayer } from './escapeLayers'

/*
 * The layer registry the DrawerShell reads before it closes on Escape,
 * and a guard: a Martis popup that closes on an outside click is a layer,
 * so it registers with useEscapeLayer, and a new one cannot bring back an
 * Escape that closes the drawer under it.
 */

const sources = import.meta.glob('../components/**/*.tsx', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

// Popups that close on an outside click without joining the registry,
// each for a reason the DrawerShell already covers.
const NOT_LAYERS: Record<string, string> = {
  '../components/PreferencesMenu.tsx': 'a PrimeReact OverlayPanel, which closes itself on Escape and counts as an open overlay',
  '../components/MartisTooltip.tsx': 'a tooltip, not a layer',
  '../components/fields/TrixField.tsx': 'its image modal takes the modal history lock',
}

function Layer({ open }: { open: boolean }) {
  useEscapeLayer(open, () => {})
  return null
}

describe('escape layers', () => {
  it('counts a layer only while it is open', () => {
    const { rerender, unmount } = render(<Layer open={false} />)
    expect(getOpenLayerCount()).toBe(0)
    expect(hasOpenLayer()).toBe(false)

    rerender(<Layer open />)
    expect(getOpenLayerCount()).toBe(1)
    expect(hasOpenLayer()).toBe(true)

    unmount()
    expect(getOpenLayerCount()).toBe(0)
  })

  it('counts an open PrimeReact overlay, and not one leaving', () => {
    const overlay = document.createElement('div')
    overlay.setAttribute('data-pr-is-overlay', 'true')
    document.body.appendChild(overlay)
    expect(hasOpenLayer()).toBe(true)

    overlay.classList.add('p-connected-overlay-exit')
    expect(hasOpenLayer()).toBe(false)
    overlay.remove()
  })

  it('closes only the layer on top', () => {
    const closed: string[] = []
    function Two() {
      const [a, setA] = useState(true)
      const [b, setB] = useState(true)
      useEscapeLayer(a, () => { closed.push('a'); setA(false) })
      useEscapeLayer(b, () => { closed.push('b'); setB(false) })
      return null
    }
    render(<Two />)

    act(() => { document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })) })
    expect(closed).toEqual(['b'])
    act(() => { document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })) })
    expect(closed).toEqual(['b', 'a'])
  })

  it('registers every Martis popup that closes on an outside click', () => {
    const missing = Object.entries(sources)
      .filter(([path]) => !path.includes('.test.'))
      .filter(([, source]) => /addEventListener\(\s*['"](mousedown|pointerdown)['"]/.test(source))
      .filter(([path, source]) => !source.includes('useEscapeLayer(') && !(path in NOT_LAYERS))
      .map(([path]) => path)

    expect(missing).toEqual([])
  })
})
