import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import path from 'node:path'

// The Textarea field auto-resizes: PrimeReact hides its overflow and sets its
// height inline to fit the text. The 36px input lock and a
// `height: auto !important` on every `.p-inputtextarea` beat that inline
// height, so a long text was cut off at the row count with no scrollbar and
// no wheel scrolling. The rendered behaviour is checked in a browser; this
// pins the rules.

const css = readFileSync(path.resolve(process.cwd(), 'resources/css/martis.css'), 'utf8')

function rules(): Array<{ selectors: string[]; body: string }> {
  const found: Array<{ selectors: string[]; body: string }> = []
  const pattern = /([^{}]+)\{([^}]*)\}/g
  let match: RegExpExecArray | null
  while ((match = pattern.exec(css)) !== null) {
    const selectors = match[1].replace(/\/\*[\s\S]*?\*\//g, '').split(',').map((s) => s.trim())
    found.push({ selectors, body: match[2] })
  }
  return found
}

const forcedHeight = /(^|[\s;])height:[^;]*!important/

describe('the textarea height rules', () => {
  it('leave an auto-resizing textarea out of the 36px input lock', () => {
    const lock = rules().find((r) => r.selectors.includes('.p-inputnumber-input') && forcedHeight.test(r.body))

    expect(lock, 'the 36px input lock is in martis.css').toBeTruthy()
    expect(lock!.selectors).toContain('.p-inputtext:not(:where(.p-inputtextarea-resizable))')
    expect(lock!.selectors).toContain('.martis-input:not(:where(.p-inputtextarea-resizable))')
    expect(lock!.selectors).not.toContain('.p-inputtext')
    expect(lock!.selectors).not.toContain('.martis-input')
  })

  it('force a height only on a textarea that does not auto-resize', () => {
    for (const rule of rules().filter((r) => r.selectors.includes('.p-inputtextarea'))) {
      expect(rule.body).not.toMatch(forcedHeight)
    }

    const fixed = rules().find((r) => r.selectors.includes('.p-inputtextarea:not(.p-inputtextarea-resizable)'))

    expect(fixed?.body).toContain('height: auto !important;')
  })
})
