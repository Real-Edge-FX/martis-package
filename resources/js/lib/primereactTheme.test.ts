// @vitest-environment node
import postcss from 'postcss'
import { compile, Logger } from 'sass'
import { beforeAll, describe, expect, it } from 'vitest'

// The PrimeReact theme is compiled from its SASS source with every lara
// colour variable pointed at a Martis token (resources/sass/primereact).
// These checks run on the compiled CSS, so a colour the source still
// hard-codes (a new upstream rule, a variable left unmapped) fails here
// instead of reaching a component as stock indigo or a dark surface.
const themePath = decodeURIComponent(new URL('../../sass/primereact/theme.scss', import.meta.url).pathname)
const theme = compile(themePath, { style: 'expanded', logger: Logger.silent }).css
const declarations = (() => {
  const out: { selector: string; prop: string; value: string }[] = []
  postcss.parse(theme).walkDecls((decl) => {
    if (decl.prop.startsWith('--')) return
    const rule = decl.parent as { selector?: string }
    out.push({ selector: rule.selector ?? '', prop: decl.prop, value: decl.value })
  })
  return out
})()
let declaredTokens = new Set<string>()

beforeAll(async () => {
  // Vitest blanks stylesheet imports (`?raw` included), so the tokens are
  // read from disk. The frontend tsc has no @types/node; the one Node API
  // used here is typed locally, as in test-setup.ts.
  const fs = (await import(/* @vite-ignore */ 'node:fs' as string)) as {
    readFileSync(path: string, encoding: 'utf8'): string
  }
  const css = fs.readFileSync(decodeURIComponent(new URL('../../css/martis.css', import.meta.url).pathname), 'utf8')
  declaredTokens = new Set([...css.matchAll(/(--martis-[a-z0-9-]+)\s*:/g)].map((m) => m[1]))
})

function literalColours(value: string): string[] {
  return value.replace(/var\([^()]*\)/g, '').match(/#[0-9a-f]{3,8}\b|rgba?\(\s*\d[^)]*\)/gi) ?? []
}

function valueOf(selector: string, prop: string): string | undefined {
  return declarations.find((d) => d.selector === selector && d.prop === prop)?.value
}

describe('PrimeReact theme compiled with the Martis tokens', () => {
  it('paints every component with the tokens', () => {
    const unexpected = declarations.flatMap(({ selector, prop, value }) =>
      literalColours(value)
        .filter((colour) => {
          // White text on a severity fill, as on the .martis-btn-* buttons.
          if (prop === 'color' && /^#fff(fff)?$/i.test(colour) && /-(info|success|warning|danger)\b/.test(selector)) return false
          // A fully transparent stop in a gradient.
          if (/^rgba\(\s*\d+,\s*\d+,\s*\d+,\s*0\s*\)$/.test(colour)) return false
          // Galleria and the image preview keep a dark scrim around media in both modes.
          if (/\.p-galleria|\.p-image-/.test(selector)) return false
          return true
        })
        .map((colour) => `${selector} { ${prop}: ${colour} }`),
    )

    expect(unexpected).toEqual([])
  })

  it('reads only tokens Martis declares', () => {
    const used = new Set(declarations.flatMap(({ value }) => [...value.matchAll(/var\((--martis-[a-z0-9-]+)/g)].map((m) => m[1])))

    expect([...used].filter((token) => !declaredTokens.has(token))).toEqual([])
    for (const core of ['accent', 'accent-contrast', 'surface', 'card', 'text', 'text-muted', 'border', 'input-bg', 'overlay', 'focus-ring', 'danger', 'success']) {
      expect(used).toContain(`--martis-${core}`)
    }
  })

  it('emits colour-mix expressions the browser accepts', () => {
    const invalid = declarations.filter(
      ({ value }) => /rgba?\(\s*(var|color-mix)\(/.test(value) || /color-mix\([^)]*\b(1\d\d|[2-9]\d\d)%/.test(value),
    )

    expect(invalid).toEqual([])
  })

  it('follows the accent and the surfaces on the states Martis relies on', () => {
    expect(valueOf('.p-button', 'background')).toBe('var(--martis-accent)')
    expect(valueOf('.p-inputtext', 'background')).toBe('var(--martis-input-bg)')
    expect(valueOf('.p-dropdown-panel', 'background')).toBe('var(--martis-surface)')
    expect(valueOf('.p-dropdown-panel .p-dropdown-items .p-dropdown-item.p-highlight', 'background')).toBe('var(--martis-accent-bg-light)')
    expect(valueOf('.p-multiselect-panel', 'background')).toBe('var(--martis-surface)')
    expect(valueOf('.p-overlaypanel', 'background')).toBe('var(--martis-card)')
    expect(valueOf('.p-datepicker table td > span.p-highlight', 'background')).toBe('var(--martis-accent-bg-light)')
    expect(valueOf('.p-component-overlay', 'background-color')).toBe('var(--martis-overlay)')
  })
})
