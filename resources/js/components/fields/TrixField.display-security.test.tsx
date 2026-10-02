import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render } from '@testing-library/react'
import type { FieldDefinition } from '@/types'

/*
 * Security regression (F002 / F004 / F018): the Trix display injected the
 * stored HTML as it was, so a panel user who could write the attribute (the
 * JSON API takes any string) ran script in the session of whoever opened the
 * record. The display now sanitises with the shared `richText` profile, and
 * its click handler only follows an http(s) URL, since a `javascript:` URL in
 * the JSON of a `data-trix-attachment` attribute would survive any HTML
 * sanitiser that does not read JSON.
 */

const sanitizer = vi.hoisted(() => ({ passThrough: false }))

// Lets one test hand the click handler the raw stored HTML, to pin the
// handler's own URL check independently of the sanitiser in front of it.
vi.mock('@/lib/sanitizeHtml', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/sanitizeHtml')>()
  return {
    ...actual,
    sanitizeRichText: (html: string) => (sanitizer.passThrough ? html : actual.sanitizeRichText(html)),
  }
})

import { TrixFieldDisplay } from './TrixField'

function makeField(extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute: 'body', label: 'Body', type: 'trix', alwaysShow: true,
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], ...extra,
  } as unknown as FieldDefinition
}

const attachmentFigure = (json: string, classes = 'attachment attachment--file'): string =>
  `<figure class="${classes}" data-trix-attachment='${json}'><figcaption>Report.pdf</figcaption></figure>`

const original = window.location
const openSpy = vi.fn()

beforeEach(() => {
  sanitizer.passThrough = false
  Object.defineProperty(window, 'location', { configurable: true, value: { href: 'http://localhost/martis/resources/posts/1' } })
  vi.spyOn(window, 'open').mockImplementation(openSpy)
  vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: original })
  openSpy.mockClear()
  vi.restoreAllMocks()
})

describe('TrixFieldDisplay sanitising', () => {
  it('renders a stored <img onerror> and <script> without the handler and the script', () => {
    const { container } = render(
      <TrixFieldDisplay field={makeField()} value={'<div>Hello<img src=x onerror="window.__pwned = 1"><script>window.__pwned = 2</script></div>'} />,
    )

    const content = container.querySelector('.trix-content') as HTMLElement
    expect(content.textContent).toContain('Hello')
    expect(content.querySelector('script')).toBeNull()
    expect(content.querySelector('img')?.getAttribute('onerror')).toBeNull()
    expect(content.innerHTML.toLowerCase()).not.toContain('onerror')
    expect((window as unknown as Record<string, unknown>).__pwned).toBeUndefined()
  })

  it('removes the data-pr-* attributes a stored value uses to make the global tooltip render its HTML', () => {
    const { container } = render(
      <TrixFieldDisplay
        field={makeField()}
        value={'<span data-pr-tooltip="<img src=x onerror=alert(1)>" data-pr-tooltip-html="true" style="position:fixed;inset:0">hover</span>'}
      />,
    )

    const content = container.querySelector('.trix-content') as HTMLElement
    expect(content.textContent).toBe('hover')
    expect(content.innerHTML).not.toContain('data-pr-')
    expect(content.innerHTML).not.toContain('style=')
  })

  it('keeps the formatting and a Trix attachment, with the attributes the display reads', () => {
    const json = JSON.stringify({ url: 'https://cdn.example.com/a.png', width: 300, height: 200 })
    const { container } = render(
      <TrixFieldDisplay
        field={makeField()}
        value={`<div><strong>Bold</strong></div><figure class="attachment attachment--preview" data-trix-attachment='${json}' data-trix-content-type="image/png"><img src="https://cdn.example.com/a.png"><figcaption class="attachment__caption">a.png</figcaption></figure>`}
      />,
    )

    const content = container.querySelector('.trix-content') as HTMLElement
    expect(content.querySelector('strong')?.textContent).toBe('Bold')
    const figure = content.querySelector('figure.attachment') as HTMLElement
    expect(figure.getAttribute('data-trix-attachment')).toBe(json)
    expect(figure.getAttribute('data-trix-content-type')).toBe('image/png')
    // The saved width is restored from the attachment JSON.
    expect(figure.style.width).toBe('300px')
  })

  it('drops the attachment JSON of a figure whose URL is not a web URL', () => {
    const { container } = render(
      <TrixFieldDisplay field={makeField()} value={attachmentFigure('{"url":"javascript:window.__pwned=1"}')} />,
    )

    const figure = container.querySelector('figure') as HTMLElement
    expect(figure.getAttribute('data-trix-attachment')).toBeNull()

    fireEvent.click(figure)
    expect(window.location.href).toBe('http://localhost/martis/resources/posts/1')
    expect(openSpy).not.toHaveBeenCalled()
  })
})

describe('TrixFieldDisplay click handling', () => {
  it('follows an http(s) attachment URL on the same page by default', () => {
    const { container } = render(
      <TrixFieldDisplay field={makeField()} value={attachmentFigure('{"url":"https://cdn.example.com/report.pdf"}')} />,
    )

    fireEvent.click(container.querySelector('figure') as HTMLElement)

    expect(window.location.href).toBe('https://cdn.example.com/report.pdf')
  })

  it('follows a same-origin attachment path', () => {
    const { container } = render(
      <TrixFieldDisplay field={makeField()} value={attachmentFigure('{"url":"/storage/report.pdf"}')} />,
    )

    fireEvent.click(container.querySelector('figure') as HTMLElement)

    expect(window.location.href).toBe('/storage/report.pdf')
  })

  it('opens an http(s) attachment in a new tab when the field says so', () => {
    const { container } = render(
      <TrixFieldDisplay
        field={makeField({ linkClickBehavior: 'new_tab' })}
        value={attachmentFigure('{"url":"https://cdn.example.com/report.pdf"}')}
      />,
    )

    fireEvent.click(container.querySelector('figure') as HTMLElement)

    expect(openSpy).toHaveBeenCalledWith('https://cdn.example.com/report.pdf', '_blank', 'noopener,noreferrer')
  })

  describe('with the raw stored HTML handed to the handler (the sanitiser out of the way)', () => {
    beforeEach(() => {
      sanitizer.passThrough = true
    })

    it.each(['same_page', 'new_tab'])('ignores a javascript: URL in the attachment JSON (%s)', (linkClickBehavior) => {
      const { container } = render(
        <TrixFieldDisplay field={makeField({ linkClickBehavior })} value={attachmentFigure('{"url":"javascript:window.__pwned=1"}')} />,
      )

      const figure = container.querySelector('figure') as HTMLElement
      const event = new MouseEvent('click', { bubbles: true, cancelable: true })
      figure.dispatchEvent(event)

      expect(window.location.href).toBe('http://localhost/martis/resources/posts/1')
      expect(openSpy).not.toHaveBeenCalled()
    })

    it('ignores a javascript: href in the attachment JSON', () => {
      const { container } = render(
        <TrixFieldDisplay field={makeField()} value={attachmentFigure('{"href":"javascript:window.__pwned=1"}')} />,
      )

      fireEvent.click(container.querySelector('figure') as HTMLElement)

      expect(window.location.href).toBe('http://localhost/martis/resources/posts/1')
      expect(openSpy).not.toHaveBeenCalled()
    })

    it('ignores a data: or javascript: URL a preview figure falls back to', () => {
      const { container } = render(
        <TrixFieldDisplay
          field={makeField({ linkClickBehavior: 'new_tab' })}
          value={'<figure class="attachment attachment--preview"><img src="data:text/html,<script>alert(1)</script>"></figure>'}
        />,
      )

      fireEvent.click(container.querySelector('figure') as HTMLElement)

      expect(openSpy).not.toHaveBeenCalled()
      expect(document.querySelector('.fixed img')).toBeNull()
    })

    it('does not open a javascript: link in a new tab, and stops the click', () => {
      const { container } = render(
        <TrixFieldDisplay field={makeField({ linkClickBehavior: 'new_tab' })} value={'<p><a href="javascript:window.__pwned=1">x</a></p>'} />,
      )

      const link = container.querySelector('a') as HTMLElement
      const event = new MouseEvent('click', { bubbles: true, cancelable: true })
      link.dispatchEvent(event)

      expect(openSpy).not.toHaveBeenCalled()
      expect(event.defaultPrevented).toBe(true)
    })

    it('stops the click on a javascript: link on the same page too', () => {
      const { container } = render(
        <TrixFieldDisplay field={makeField()} value={'<p><a href="javascript:window.__pwned=1">x</a></p>'} />,
      )

      const link = container.querySelector('a') as HTMLElement
      const event = new MouseEvent('click', { bubbles: true, cancelable: true })
      link.dispatchEvent(event)

      expect(event.defaultPrevented).toBe(true)
    })

    it('still opens an http(s) link in a new tab, and leaves a mailto: link to the browser', () => {
      const { container } = render(
        <TrixFieldDisplay
          field={makeField({ linkClickBehavior: 'new_tab' })}
          value={'<p><a href="https://example.com/x">web</a> <a href="mailto:a@example.com">mail</a></p>'}
        />,
      )

      const [web, mail] = Array.from(container.querySelectorAll('a'))
      fireEvent.click(web)
      expect(openSpy).toHaveBeenCalledWith('https://example.com/x', '_blank', 'noopener,noreferrer')

      openSpy.mockClear()
      const mailEvent = new MouseEvent('click', { bubbles: true, cancelable: true })
      mail.dispatchEvent(mailEvent)
      expect(openSpy).not.toHaveBeenCalled()
      expect(mailEvent.defaultPrevented).toBe(false)
    })
  })
})
