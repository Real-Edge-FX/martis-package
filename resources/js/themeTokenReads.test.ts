import { describe, it, expect } from 'vitest'
import ts from 'typescript'
// The scaffolded theme declares exactly the variables martis.css does
// (ThemeTokenDriftTest pins it); Vitest does not load .css sources as text.
import themeStub from '../../stubs/theme.css.stub?raw'

/*
 * The components read theme variables through `var(--martis-*)` strings.
 * A read of a variable nothing defines falls back to its inline default (or
 * to nothing) whatever the theme says, as `--martis-primary` and
 * `--martis-brand-500` did. The strings are found with the TypeScript
 * parser, not by stripping comments with a scanner: an apostrophe in JSX
 * text, a quote inside a regex literal or `//` in JSX text all threw such a
 * scanner off. tests/Unit/ThemeTokenDriftTest.php checks martis.css the
 * same way and keeps the reference in step with these lists.
 *
 * The same walk checks the spinners: Tailwind's preflight is off
 * (tailwind.config.ts), so `border-2` without a border style draws nothing
 * and an `animate-spin` ring stays invisible.
 */

/** Every component source, keyed by its path under resources/js, the tests left out. */
const SOURCES: Record<string, string> = Object.fromEntries(
  Object.entries(import.meta.glob('./**/*.{ts,tsx}', { query: '?raw', import: 'default', eager: true }) as Record<string, string>)
    .filter(([path]) => !path.includes('.test.') && !path.endsWith('.d.ts')),
)

/** The variables the config (not a stylesheet) gives a value. */
const CONFIG_VARIABLES = ['--martis-brand-logo-height-auth', '--martis-brand-logo-height-menu']

/** Set inline by the grid components, or optional hooks: named in docs/theming.md ("Not counted"). */
const ALLOWED_UNDEFINED = [
  '--martis-field-span', '--martis-field-span-md', '--martis-field-span-lg', '--martis-field-columns',
  '--martis-card-span', '--martis-card-span-md', '--martis-card-span-lg', '--martis-filter-span',
  '--martis-tooltip-bg', '--martis-tooltip-text',
]

const NAME = /--martis-[A-Za-z0-9_-]+/

function definedVariables(): Set<string> {
  const css = themeStub.replace(/\/\*[\s\S]*?\*\//g, '')
  const names = [...css.matchAll(new RegExp(`(${NAME.source})\\s*:`, 'g'))].map((m) => m[1])
  return new Set([...names, ...CONFIG_VARIABLES])
}

/**
 * The string pieces of a source: literals, template pieces and JSX text,
 * each flagged when a `${...}` follows it (its last name is completed at
 * runtime). Comments are never visited.
 */
function stringPieces(fileName: string, source: string): Array<{ text: string; open: boolean }> {
  const file = ts.createSourceFile(fileName, source, ts.ScriptTarget.Latest, true, fileName.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS)
  const pieces: Array<{ text: string; open: boolean }> = []
  const visit = (node: ts.Node): void => {
    if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node) || ts.isJsxText(node)) {
      pieces.push({ text: node.text, open: false })
    } else if (ts.isTemplateExpression(node)) {
      pieces.push({ text: node.head.text, open: true })
      node.templateSpans.forEach((span) => pieces.push({ text: span.literal.text, open: !ts.isTemplateTail(span.literal) }))
    }
    ts.forEachChild(node, visit)
  }
  visit(file)
  return pieces
}

/** The `var(--martis-*)` names a source reads, a name completed at runtime left out. */
function reads(fileName: string, source: string): string[] {
  return stringPieces(fileName, source).flatMap(({ text, open }) =>
    [...text.matchAll(new RegExp(`var\\(\\s*(${NAME.source})`, 'g'))]
      .filter((m) => !(open && (m.index ?? 0) + m[0].length === text.length))
      .map((m) => m[1]),
  )
}

describe('reads found through the TypeScript parser', () => {
  it('sees a read after the constructs a comment stripper trips on, and none in comments', () => {
    const source = [
      "const pattern = /['\"]/",
      'export function C() {',
      "  return <p>Don't go // there <span style={{ color: 'var(--martis-after-jsx-text)' }} /></p>",
      '}',
      "const x = 'var(--martis-after-a-regex)' // var(--martis-in-a-line-comment)",
      '/* var(--martis-in-a-block-comment) */',
      'const dynamic = `var(--martis-avatar-${n})`',
    ].join('\n')

    expect(reads('probe.tsx', source).sort()).toEqual(['--martis-after-a-regex', '--martis-after-jsx-text'])
  })
})

describe('the components', () => {
  const files = Object.keys(SOURCES)

  it('read no theme variable that nothing defines, other than the inline layout variables and the documented hooks', () => {
    const defined = definedVariables()
    const undefinedReads = files.flatMap((path) =>
      reads(path, SOURCES[path])
        .filter((name) => !defined.has(name) && !ALLOWED_UNDEFINED.includes(name))
        .map((name) => `${name} in ${path}`),
    )

    expect(files.length).toBeGreaterThan(100)
    expect([...new Set(undefinedReads)]).toEqual([])
  })

  it('give every spinning ring a border style, which preflight does not provide', () => {
    const invisible = files.flatMap((path) =>
      stringPieces(path, SOURCES[path])
        .map(({ text }) => text)
        .filter((text) => /\banimate-spin\b/.test(text) && /\bborder(-\d+)?\b/.test(text) && !/\bborder-(solid|dashed|dotted|double)\b/.test(text))
        .map((text) => `${path}: "${text}"`),
    )

    expect(invisible).toEqual([])
  })
})
