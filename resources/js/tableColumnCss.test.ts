import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import path from 'node:path'

// `Field::textAlign()` and `Resource::stickyActions()` are drawn by CSS that
// jsdom cannot evaluate (PrimeReact's rules live in an `@layer`), so the
// rendered result was checked in a browser against the built CSS, light and
// dark: a right-aligned plain header moves to the right, and the sticky
// actions cell shows the colour of its row in every state. This pins the rules.

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

/** The declarations of the rule that lists the selector. */
function bodyOf(selector: string): string {
  const rule = rules().find((r) => r.selectors.includes(selector))
  expect(rule, `a rule for ${selector}`).toBeTruthy()
  return rule!.body
}

const STICKY = '.martis-datatable.martis-datatable-sticky-actions'
const CELL = `${STICKY} .p-datatable-tbody > tr`

describe('a plain header of an aligned column', () => {
  it('places the header content with justify-content', () => {
    expect(bodyOf('.martis-datatable th.martis-col-align-right > .p-column-header-content')).toMatch(/justify-content:\s*flex-end/)
    expect(bodyOf('.martis-datatable th.martis-col-align-center > .p-column-header-content')).toMatch(/justify-content:\s*center/)
  })
})

describe('the sticky actions cell', () => {
  it('takes the colour of the plain row, which the TR draws (sidebar, surface in light)', () => {
    expect(bodyOf(`${CELL} > td.martis-actions-col`)).toMatch(/background-color:\s*var\(--martis-sidebar\)\s*!important/)
    expect(bodyOf(`:where(html:not(.dark)) ${CELL} > td.martis-actions-col`)).toMatch(/background-color:\s*var\(--martis-surface\)\s*!important/)
  })

  it('adds no theme variable: the drift tests count the variables martis.css defines', () => {
    expect(css).not.toContain('--martis-sticky-row-bg')
  })

  it('takes the even row colour whether or not the table is striped', () => {
    expect(bodyOf(`${CELL}:nth-child(even) > td.martis-actions-col`)).toMatch(/background-color:\s*var\(--martis-row-even\)\s*!important/)
  })

  it('takes the card colour a selected row shows through to, and the hover colour on hover', () => {
    expect(bodyOf(`${CELL}.martis-row-selected > td.martis-actions-col`)).toMatch(/background-color:\s*var\(--martis-card\)\s*!important/)
    expect(bodyOf(`${CELL}:hover > td.martis-actions-col`)).toMatch(/background-color:\s*var\(--martis-row-hover\)\s*!important/)
    expect(bodyOf(`${CELL}.martis-row-selected:hover > td.martis-actions-col`)).toMatch(/background-color:\s*var\(--martis-row-hover\)\s*!important/)
  })

  it('keeps the header cell on the header colour', () => {
    expect(bodyOf(`${STICKY} .p-datatable-thead > tr > th.martis-actions-col`)).toMatch(/background-color:\s*var\(--martis-table-header-bg\)\s*!important/)
  })
})
