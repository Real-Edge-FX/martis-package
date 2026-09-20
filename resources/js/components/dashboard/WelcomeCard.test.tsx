import { beforeAll, describe, expect, it, vi } from 'vitest'
import { render } from '@testing-library/react'
import { WelcomeCard } from './WelcomeCard'

// jsdom has no matchMedia; usePrefersReducedMotion() reads it on mount.
beforeAll(() => {
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      addListener: vi.fn(),
      removeListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  })
})

// The version badge and its shimmer sweep were literal whites in inline
// styles, outside the token system: a theme could not soften or disable the
// bright band that sweeps over the badge text every 3.5 s. They now read
// `--martis-brand-badge-bg` / `--martis-brand-badge-border` /
// `--martis-brand-shimmer` with the previous literals as fallbacks.
describe('WelcomeCard brand tokens', () => {
  it('paints the version badge and its shimmer from the brand tokens', () => {
    const { container } = render(<WelcomeCard version="9.9.9" />)

    const badge = container.querySelector('.mwc-badge') as HTMLElement
    const shimmer = container.querySelector('.mwc-shimmer') as HTMLElement

    expect(badge).toBeTruthy()
    expect(badge.textContent).toBe('v9.9.9')
    expect(badge.style.background).toContain('var(--martis-brand-badge-bg')
    expect(badge.style.border).toContain('var(--martis-brand-badge-border')

    expect(shimmer).toBeTruthy()
    // The white band exists only as the token's fallback: a theme setting
    // `--martis-brand-shimmer: transparent` removes the sweep entirely.
    expect(shimmer.style.background).toContain('var(--martis-brand-shimmer, rgba(255,255,255,0.28)) 50%')
    const outsideFallback = shimmer.style.background.replace(/var\(--martis-brand-shimmer,[^)]*\)\)/, 'TOKEN')
    expect(outsideFallback).not.toMatch(/rgba\(255,\s*255,\s*255/)
  })
})
