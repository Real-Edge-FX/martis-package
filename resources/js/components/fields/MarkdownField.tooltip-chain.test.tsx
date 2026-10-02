import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { MartisTooltip } from '@/components/MartisTooltip'
import { MarkdownFieldDisplay, MarkdownFieldInput, renderMarkdown } from './MarkdownField'

/*
 * Security regression (F003 / F102 / F110): DOMPurify's defaults keep every
 * data-* attribute and the style attribute, so Markdown could carry a span
 * with `data-pr-tooltip` (an <img onerror> payload), `data-pr-tooltip-html`
 * and a full-viewport `position: fixed` style through sanitising. The global
 * tooltip then wrote the payload into the page when the viewer moved the
 * pointer, and the handler ran in the viewer's session. Both halves are
 * closed: the Markdown profile drops those attributes, and the tooltip only
 * renders HTML for a trigger a package component registered.
 */

const PAYLOAD = '<span data-pr-tooltip="<img src=x onerror=alert(1)>" data-pr-tooltip-html="true" style="position:fixed;inset:0;z-index:9999">hover me</span>'

function field(extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute: 'content', label: 'Content', type: 'markdown', alwaysShow: true,
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], ...extra,
  } as unknown as FieldDefinition
}

describe('renderMarkdown, the tooltip attributes and the style attribute', () => {
  it.each(['default', 'commonmark'])('drops data-pr-tooltip, data-pr-tooltip-html and style from raw HTML (%s)', (preset) => {
    const html = renderMarkdown(`before ${PAYLOAD} after`, preset)

    expect(html).not.toContain('data-pr-')
    expect(html).not.toContain('style')
    expect(html).not.toContain('onerror')
    expect(html).toContain('hover me')
  })

  it('drops every other data-* attribute too', () => {
    const html = renderMarkdown('<p data-anything="1">x</p>', 'default')

    expect(html).not.toContain('data-')
  })

  it('drops a <style> element and a form', () => {
    const html = renderMarkdown('<style>body{display:none}</style><form action="https://evil.example"><input name="p"></form>text', 'default')

    expect(html).not.toContain('<style')
    expect(html).not.toContain('<form')
    expect(html).toContain('text')
  })

  it('still renders task lists, tables and fenced code', () => {
    const html = renderMarkdown('- [x] done\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n```js\nlet x = 1\n```', 'default')

    expect(html).toContain('type="checkbox"')
    expect(html).toContain('<table>')
    expect(html).toContain('<code')
  })
})

describe('the Markdown display and preview', () => {
  it('renders the stored payload without the attributes (detail view)', () => {
    const { container } = render(<MarkdownFieldDisplay field={field()} value={PAYLOAD} />)

    expect(container.querySelector('[data-pr-tooltip]')).toBeNull()
    expect(container.querySelector('[data-pr-tooltip-html]')).toBeNull()
    expect(container.querySelector('[style]')).toBeNull()
    expect(container.textContent).toContain('hover me')
  })

  it('renders the stored payload without the attributes (edit form preview)', () => {
    const { container } = render(<MarkdownFieldInput field={field()} value={PAYLOAD} onChange={() => {}} />)

    fireEvent.click(screen.getByRole('button', { name: /preview/i }))

    const preview = container.querySelector('.prose') as HTMLElement
    expect(preview.querySelector('[data-pr-tooltip]')).toBeNull()
    expect(preview.querySelector('[style]')).toBeNull()
    expect(preview.textContent).toContain('hover me')
  })
})

describe('the whole chain: Markdown value, then hover', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb: FrameRequestCallback) => {
      cb(0)
      return 0
    })
  })

  afterEach(() => {
    cleanup()
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('never renders the payload of a hovered record element in the tooltip', () => {
    const { container } = render(
      <>
        <MarkdownFieldDisplay field={field()} value={PAYLOAD} />
        <MartisTooltip />
      </>,
    )
    const span = container.querySelector('span') as HTMLElement

    act(() => {
      fireEvent.mouseEnter(span)
      vi.advanceTimersByTime(600)
    })

    expect(document.querySelector('[role="tooltip"]')).toBeNull()
    expect(document.querySelector('img[src="x"]')).toBeNull()
  })
})
