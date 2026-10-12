import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import path from 'node:path'

// On a phone, a top bar whose items needed more than the screen widened the
// shell's `1fr` column past the viewport (a `1fr` track's minimum is the
// min-content width of its items), and the shell's `overflow: hidden` cut
// off the right part of every page (v2.14.0). The rendered behaviour is
// checked in a browser; this pins the rules.

const css = readFileSync(path.resolve(process.cwd(), 'resources/css/martis.css'), 'utf8')

function declarations(selector: string): string {
  const start = css.indexOf(`${selector} {`)
  expect(start, `${selector} is in martis.css`).toBeGreaterThan(-1)
  return css.slice(start, css.indexOf('}', start))
}

describe('the shell content column', () => {
  it('never grows past the viewport on a phone', () => {
    expect(declarations('.martis-shell[data-mobile="true"]')).toContain('grid-template-columns: minmax(0, 1fr);')
  })

  it('never grows past the room the sidebar leaves on a wide screen', () => {
    expect(declarations('.martis-shell')).toContain('grid-template-columns: var(--sidebar-width, 248px) minmax(0, 1fr);')
  })
})

describe('the top bar', () => {
  it('can shrink below its content, the right-hand cluster cannot', () => {
    expect(declarations('.martis-tb')).toContain('min-width: 0;')
    expect(declarations('.martis-tb-right')).toContain('flex-shrink: 0;')
  })

  it('truncates the breadcrumbs instead of widening, without clipping a focus ring', () => {
    expect(declarations('.martis-tb-breadcrumbs')).toContain('min-width: 0;')
    expect(declarations('.martis-tb-breadcrumbs')).not.toContain('overflow')
    expect(declarations('.martis-breadcrumbs')).toContain('min-width: 0;')
    expect(declarations('.martis-breadcrumbs')).not.toContain('overflow')
    expect(declarations('.martis-breadcrumb')).toContain('min-width: 0;')
    expect(declarations('.martis-breadcrumb-label')).toContain('text-overflow: ellipsis;')
  })
})

describe('the slot blocks outside the bar', () => {
  it('hide when their components render nothing', () => {
    expect(declarations('.martis-sb-slots:empty,\n.martis-topnav-slots:empty')).toContain('display: none;')
  })
})
