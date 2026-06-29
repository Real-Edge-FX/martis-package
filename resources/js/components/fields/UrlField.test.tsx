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
