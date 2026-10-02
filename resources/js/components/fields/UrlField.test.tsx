import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { UrlFieldDisplay } from './UrlField'
import type { FieldDisplayProps } from './types'

/*
 * Security regression: UrlFieldDisplay rendered <a href={String(value)}>
 * with no scheme allowlist. A stored `javascript:` / `data:` / `vbscript:`
 * URL therefore became a clickable link that executed script on click
 * (stored XSS). Dangerous schemes must be neutralised — the value is
 * rendered as plain text, never as an href.
 */

function field(extra: Record<string, unknown> = {}): FieldDisplayProps['field'] {
    return { attribute: 'website', ...extra } as unknown as FieldDisplayProps['field']
}

describe('UrlFieldDisplay scheme safety', () => {
    it('renders a normal https URL as a link', () => {
        const { container } = render(<UrlFieldDisplay field={field()} value="https://example.com" />)
        const a = container.querySelector('a')
        expect(a?.getAttribute('href')).toBe('https://example.com')
    })

    it('does not emit a javascript: href', () => {
        const { container } = render(<UrlFieldDisplay field={field()} value="javascript:alert(1)" />)
        const href = container.querySelector('a')?.getAttribute('href') ?? ''
        expect(href.toLowerCase()).not.toContain('javascript:')
    })

    it('does not emit a data: href', () => {
        const { container } = render(<UrlFieldDisplay field={field()} value="data:text/html,<script>alert(1)</script>" />)
        const href = container.querySelector('a')?.getAttribute('href') ?? ''
        expect(href.toLowerCase()).not.toContain('data:')
    })

    it('still shows the raw value as text when the scheme is unsafe', () => {
        const { container } = render(<UrlFieldDisplay field={field()} value="javascript:alert(1)" />)
        expect(container.textContent).toContain('javascript:alert(1)')
    })
})

/*
 * The scheme the browser reads, not a pattern's guess: the URL parser strips
 * tabs, newlines and leading control characters before it takes the scheme,
 * so `java\tscript:` is a `javascript:` link to the browser although a regexp
 * sees no scheme at all. The check is the shared `safeUrl` helper (F121).
 */
describe('UrlFieldDisplay with a scheme the browser reads through padding', () => {
    it.each([
        ['a tab', 'java\tscript:alert(1)'],
        ['a newline', 'java\nscript:alert(1)'],
        ['a carriage return', 'java\rscript:alert(1)'],
        ['a leading control character', '\u0001javascript:alert(1)'],
        ['a leading space', '  javascript:alert(1)'],
        ['a leading tab and newline', '\t\njavascript:alert(1)'],
        ['mixed case', 'JaVaScRiPt:alert(1)'],
        ['a tab in a data: URL', 'da\tta:text/html,<script>alert(1)</script>'],
        ['a tab in a vbscript: URL', 'vb\tscript:msgbox(1)'],
        ['a file: URL', 'file:///etc/passwd'],
        ['an ftp: URL', 'ftp://example.com/x'],
    ])('renders %s as plain text, never as a link', (_label, value) => {
        const { container } = render(<UrlFieldDisplay field={field()} value={value} />)

        expect(container.querySelector('a')).toBeNull()
        expect(container.textContent).toBe(value)
    })

    it.each([
        'https://example.com/a?b=c#d',
        'http://example.com',
        'mailto:someone@example.com',
        'tel:+351210000000',
        '/resources/users/1',
        '//cdn.example.com/x',
        'example.com/docs',
    ])('still links %s', (value) => {
        const { container } = render(<UrlFieldDisplay field={field()} value={value} />)

        expect(container.querySelector('a')?.getAttribute('href')).toBe(value)
    })

    it('uses the display text of the field for the link, and for the plain text of a refused value', () => {
        const ok = render(<UrlFieldDisplay field={field({ displayText: 'Site' })} value="https://example.com" />)
        expect(ok.container.querySelector('a')?.textContent).toBe('Site')

        const refused = render(<UrlFieldDisplay field={field({ displayText: 'Site' })} value={'java\tscript:alert(1)'} />)
        expect(refused.container.querySelector('a')).toBeNull()
        expect(refused.container.textContent).toBe('Site')
    })
})

