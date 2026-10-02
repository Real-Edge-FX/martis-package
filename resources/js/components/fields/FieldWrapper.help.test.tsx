import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { FieldWrapper } from './FieldWrapper'

/*
 * Hardening (F120): `help()` is documented as inline HTML (links, bold,
 * code), and the wrapper injected it as it was. It now goes through the
 * shared `markup` profile, which keeps that markup and removes what would
 * execute, for an application that interpolates user data into its help text.
 */

function renderHelp(help: string) {
  const { container } = render(
    <FieldWrapper label="Name" help={help}>
      <input />
    </FieldWrapper>,
  )

  return container.querySelector('.martis-input-help') as HTMLElement
}

describe('FieldWrapper help text', () => {
  it('keeps the markup the help documents: links, bold, code, line breaks', () => {
    const help = renderHelp('Read <a href="https://example.com/docs">the docs</a>: <strong>bold</strong>, <code>code</code><br>next line')

    expect(help.querySelector('a')?.getAttribute('href')).toBe('https://example.com/docs')
    expect(help.querySelector('strong')?.textContent).toBe('bold')
    expect(help.querySelector('code')?.textContent).toBe('code')
    expect(help.querySelector('br')).not.toBeNull()
  })

  it('removes a handler, a script and a javascript: URL from the help', () => {
    const help = renderHelp('Hello <img src=x onerror="window.__pwned = 1"><script>window.__pwned = 2</script><a href="javascript:window.__pwned = 3">x</a>')

    expect(help.querySelector('script')).toBeNull()
    expect(help.innerHTML.toLowerCase()).not.toContain('onerror')
    expect(help.innerHTML.toLowerCase()).not.toContain('javascript:')
    expect((window as unknown as Record<string, unknown>).__pwned).toBeUndefined()
    expect(help.textContent).toContain('Hello')
  })

  it('removes the data-pr-* attributes the global tooltip reads', () => {
    const help = renderHelp('<span data-pr-tooltip="<img src=x onerror=alert(1)>" data-pr-tooltip-html="true">hint</span>')

    expect(help.innerHTML).not.toContain('data-pr-')
    expect(help.textContent).toBe('hint')
  })

  it('renders no help element without help text', () => {
    const { container } = render(
      <FieldWrapper label="Name">
        <input />
      </FieldWrapper>,
    )

    expect(container.querySelector('.martis-input-help')).toBeNull()
  })
})
