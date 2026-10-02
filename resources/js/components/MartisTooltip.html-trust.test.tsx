import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { MartisTooltip } from './MartisTooltip'
import { htmlTooltip, trustHtmlTooltip } from '@/lib/htmlTooltip'

/*
 * Security regression (F003): the global tooltip rendered the
 * `data-pr-tooltip` of any element under the pointer as HTML when the element
 * carried `data-pr-tooltip-html="true"`, and record content (a Markdown or
 * Trix value rendered as HTML) can write both attributes. The tooltip now
 * renders HTML only for a trigger registered by the code that rendered it
 * (`htmlTooltip()` / `trustHtmlTooltip()`), shows the attribute as text for
 * every other element, and sanitises the HTML it does render.
 */

const PAYLOAD = '<img src=x onerror="window.__pwned = 1"><b>bold</b>'

beforeEach(() => {
  vi.useFakeTimers()
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(() => ({
    left: 100, top: 100, width: 40, height: 20, x: 100, y: 100, right: 140, bottom: 120, toJSON: () => ({}),
  }) as DOMRect)
  vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb: FrameRequestCallback) => {
    cb(0)
    return 0
  })
})

afterEach(() => {
  cleanup()
  document.body.replaceChildren()
  vi.useRealTimers()
  vi.restoreAllMocks()
})

function hover(trigger: HTMLElement) {
  render(<MartisTooltip />)
  act(() => {
    fireEvent.mouseEnter(trigger)
    vi.advanceTimersByTime(500)
  })

  return document.querySelector<HTMLElement>('.martis-tooltip-content')
}

/** An element parsed out of an HTML string, as record content is. */
function forged(): HTMLElement {
  const host = document.createElement('div')
  host.innerHTML = `<span data-pr-tooltip='${PAYLOAD}' data-pr-tooltip-html="true">probe</span>`
  document.body.appendChild(host)
  return host.querySelector('span') as HTMLElement
}

describe('MartisTooltip HTML trust', () => {
  it('shows the attribute of an untrusted trigger as text, whatever the opt-in says', () => {
    const content = hover(forged())

    expect(content).not.toBeNull()
    expect(content!.textContent).toContain('<img src=x')
    expect(content!.querySelector('img')).toBeNull()
    expect(content!.querySelector('b')).toBeNull()
    // The plain-text variant of the bubble, not the roomier HTML one.
    expect(content!.style.fontSize).toBe('11px')
  })

  it('renders HTML for a trigger the package registered, sanitised', () => {
    const trigger = document.createElement('span')
    trigger.setAttribute('data-pr-tooltip', PAYLOAD)
    trigger.setAttribute('data-pr-tooltip-html', 'true')
    trustHtmlTooltip(trigger)
    document.body.appendChild(trigger)

    const content = hover(trigger)

    expect(content!.querySelector('b')?.textContent).toBe('bold')
    expect(content!.querySelector('img')?.getAttribute('onerror')).toBeNull()
    expect(content!.innerHTML.toLowerCase()).not.toContain('onerror')
    expect((window as unknown as Record<string, unknown>).__pwned).toBeUndefined()
    expect(content!.style.fontSize).toBe('12px')
  })

  it('strips the data-pr-* attributes from the HTML it renders', () => {
    const trigger = document.createElement('span')
    trigger.setAttribute('data-pr-tooltip', '<span data-pr-tooltip="x" data-pr-tooltip-html="true">nested</span>')
    trigger.setAttribute('data-pr-tooltip-html', 'true')
    trustHtmlTooltip(trigger)
    document.body.appendChild(trigger)

    const content = hover(trigger)

    expect(content!.textContent).toContain('nested')
    expect(content!.innerHTML).not.toContain('data-pr-')
  })

  it('renders HTML for an element React rendered with the htmlTooltip() props', () => {
    const { container } = render(<span {...htmlTooltip('<strong>Re-index</strong><br>Rebuilds the index.', 'top')}>?</span>)
    const trigger = container.querySelector('span') as HTMLElement

    const content = hover(trigger)

    expect(content!.querySelector('strong')?.textContent).toBe('Re-index')
    expect(content!.querySelector('br')).not.toBeNull()
  })

  it('does not trust an element because it sits inside a trusted one', () => {
    const outer = document.createElement('div')
    outer.setAttribute('data-pr-tooltip', 'outer')
    outer.setAttribute('data-pr-tooltip-html', 'true')
    trustHtmlTooltip(outer)
    outer.innerHTML = `<span data-pr-tooltip='${PAYLOAD}' data-pr-tooltip-html="true">inner</span>`
    document.body.appendChild(outer)

    const content = hover(outer.querySelector('span') as HTMLElement)

    expect(content!.querySelector('img')).toBeNull()
    expect(content!.textContent).toContain('<img src=x')
  })

  it('keeps a plain-text trigger as text', () => {
    const trigger = document.createElement('button')
    trigger.setAttribute('data-pr-tooltip', '<b>x</b>')
    document.body.appendChild(trigger)

    const content = hover(trigger)

    expect(content!.querySelector('b')).toBeNull()
    expect(content!.textContent).toBe('<b>x</b>')
  })
})
