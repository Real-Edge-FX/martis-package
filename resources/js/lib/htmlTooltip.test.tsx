import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { htmlTooltip, isTrustedHtmlTooltip, trustHtmlTooltip } from './htmlTooltip'

/*
 * The registry of tooltip triggers that may show HTML. Membership comes only
 * from the code that renders the element (the props or the ref callback), so
 * an element parsed out of an HTML string, such as record content, is never
 * in it whatever attributes it carries.
 */

describe('htmlTooltip', () => {
  it('returns the tooltip attributes with the registering ref', () => {
    const props = htmlTooltip('<b>x</b>', 'left')

    expect(props['data-pr-tooltip']).toBe('<b>x</b>')
    expect(props['data-pr-tooltip-html']).toBe('true')
    expect(props['data-pr-position']).toBe('left')
    expect(props.ref).toBe(trustHtmlTooltip)
  })

  it('leaves the position out when none is given', () => {
    expect('data-pr-position' in htmlTooltip('x')).toBe(false)
  })

  it('registers the element React renders when the props are spread onto it', () => {
    const { container } = render(<span {...htmlTooltip('<b>x</b>')}>trigger</span>)
    const trigger = container.querySelector('span') as HTMLElement

    expect(trigger.getAttribute('data-pr-tooltip')).toBe('<b>x</b>')
    expect(isTrustedHtmlTooltip(trigger)).toBe(true)
  })
})

describe('trustHtmlTooltip', () => {
  it('registers an element, and ignores the null a ref gets on unmount', () => {
    const element = document.createElement('div')

    expect(isTrustedHtmlTooltip(element)).toBe(false)
    trustHtmlTooltip(element)
    expect(isTrustedHtmlTooltip(element)).toBe(true)
    expect(() => trustHtmlTooltip(null)).not.toThrow()
  })

  it('never trusts an element parsed from HTML, whatever attributes it carries', () => {
    const host = document.createElement('div')
    host.innerHTML = '<span data-pr-tooltip="<img src=x onerror=alert(1)>" data-pr-tooltip-html="true">hover</span>'
    const forged = host.querySelector('span') as HTMLElement

    expect(forged.getAttribute('data-pr-tooltip-html')).toBe('true')
    expect(isTrustedHtmlTooltip(forged)).toBe(false)
  })
})
