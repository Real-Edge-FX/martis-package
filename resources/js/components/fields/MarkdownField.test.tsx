import { describe, expect, it } from 'vitest'
import { renderMarkdown } from './MarkdownField'

/*
 * Security regression: MarkdownField fed marked.parse() output straight
 * into dangerouslySetInnerHTML. `marked` does NOT sanitize HTML, so a
 * stored markdown value containing raw <script>, event-handler
 * attributes, or javascript: URLs executed as stored XSS on every
 * viewer of the record. renderMarkdown() now runs the output through
 * DOMPurify.
 */

describe('renderMarkdown sanitization', () => {
    it('strips <script> tags from rendered HTML', () => {
        const html = renderMarkdown('hello <script>alert(1)</script> world', 'default')
        expect(html).not.toContain('<script')
        expect(html).not.toContain('alert(1)')
    })

    it('strips event-handler attributes (onerror) from raw HTML', () => {
        const html = renderMarkdown('<img src=x onerror="alert(1)">', 'default')
        expect(html.toLowerCase()).not.toContain('onerror')
    })

    it('strips javascript: URLs from links', () => {
        const html = renderMarkdown('[click](javascript:alert(1))', 'default')
        expect(html.toLowerCase()).not.toContain('javascript:')
    })

    it('still renders legitimate markdown', () => {
        const html = renderMarkdown('# Title', 'default')
        expect(html).toContain('<h1')
        expect(html).toContain('Title')
    })

    it('zero preset escapes HTML entities (unchanged behaviour)', () => {
        const html = renderMarkdown('<b>x</b>', 'zero')
        expect(html).toContain('&lt;b&gt;')
        expect(html).not.toContain('<b>')
    })
})
