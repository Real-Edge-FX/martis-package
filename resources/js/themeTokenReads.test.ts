import { describe, it, expect } from 'vitest'
import ts from 'typescript'
// The scaffolded theme declares exactly the variables martis.css does
// (ThemeTokenDriftTest pins it); Vitest does not load .css sources as text.
import themeStub from '../../stubs/theme.css.stub?raw'
import themingDoc from '../../docs/theming.md?raw'

/*
 * The components read theme variables through `var(--martis-*)` strings and
 * through bare names (`getPropertyValue('--martis-accent')`, `setProperty`,
 * `cssVar()`, an inline custom property). A read of a variable nothing
 * defines falls back to its inline default (or to nothing) whatever the
 * theme says, as `--martis-primary` and `--martis-brand-500` did. The strings
 * are found with the TypeScript parser, not by stripping comments with a
 * scanner: an apostrophe in JSX text, a quote inside a regex literal or `//`
 * in JSX text all threw such a scanner off. tests/Unit/ThemeTokenDriftTest.php
 * checks martis.css the same way and keeps the reference in step with these
 * lists.
 *
 * The same walk checks the borders. Tailwind's preflight is off
 * (tailwind.config.ts), and preflight is what gives every element
 * `border-width: 0` and `border-style: solid`. Without it a width utility
 * (`border`, `border-b`, `divide-y`) draws nothing until a style utility
 * (`border-solid`, `divide-solid`) goes with it, and a style utility draws a
 * `medium` (3px) border on every side no utility gives a width: a one-sided
 * border reads `border-0 border-b border-solid`, a divider list
 * `divide-y divide-x-0 divide-solid`. docs/theming.md ("Borders") says the
 * same for extension authors.
 *
 * The check reads an element's class attribute through literals, templates,
 * conditions, `[...].join(' ')` and the constants and lookup tables of the
 * same file, and its inline `style`. What it cannot follow (a class string
 * from a prop or another module, a name built at runtime such as
 * `border-${side}`) is checked where the string is written, on its own: keep
 * the style utility in the same string as the width.
 */

/** Every component source, keyed by its path under resources/js, the tests left out, and the React stubs the generators write. */
const SOURCES: Record<string, string> = {
  ...Object.fromEntries(
    Object.entries(import.meta.glob('./**/*.{ts,tsx}', { query: '?raw', import: 'default', eager: true }) as Record<string, string>)
      .filter(([path]) => !path.includes('.test.') && !path.endsWith('.d.ts')),
  ),
  ...(import.meta.glob('../../stubs/*.tsx.stub', { query: '?raw', import: 'default', eager: true }) as Record<string, string>),
}

/** The variables the config (not a stylesheet) gives a value. */
const CONFIG_VARIABLES = ['--martis-brand-logo-height-auth', '--martis-brand-logo-height-menu']

/**
 * Set inline by the grid components, or optional hooks. Each one is named in
 * the "Not counted" paragraph of docs/theming.md (checked below), as the PHP
 * `themeDriftAllowedUndefined()` list is.
 */
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

function parse(fileName: string, source: string): ts.SourceFile {
  const kind = /\.tsx(\.stub)?$/.test(fileName) ? ts.ScriptKind.TSX : ts.ScriptKind.TS
  return ts.createSourceFile(fileName, source, ts.ScriptTarget.Latest, true, kind)
}

/**
 * The string pieces of a source: literals, template pieces and JSX text,
 * each flagged when a `${...}` follows it (its last name is completed at
 * runtime). Comments are never visited.
 */
function stringPieces(fileName: string, source: string): Array<{ text: string; open: boolean }> {
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
  visit(parse(fileName, source))
  return pieces
}

/**
 * The `--martis-*` names a source reads or writes: the `var(...)` reads, and
 * the names written whole (a `getPropertyValue` / `setProperty` /
 * `removeProperty` argument, a `cssVar()` read, an inline custom property
 * key). A name completed at runtime is left out.
 */
function reads(fileName: string, source: string): string[] {
  return stringPieces(fileName, source).flatMap(({ text, open }) => [
    ...[...text.matchAll(new RegExp(`var\\(\\s*(${NAME.source})`, 'g'))]
      .filter((m) => !(open && (m.index ?? 0) + m[0].length === text.length))
      .map((m) => m[1]),
    ...(!open && /^--martis-[A-Za-z0-9_-]*[A-Za-z0-9_]$/.test(text) ? [text] : []),
  ])
}

// ---------------------------------------------------------------- borders

type Side = 't' | 'r' | 'b' | 'l'
const ALL_SIDES: Side[] = ['t', 'r', 'b', 'l']
const SIDES_OF: Record<string, Side[]> = { '': ALL_SIDES, x: ['l', 'r'], y: ['t', 'b'], t: ['t'], r: ['r'], b: ['b'], l: ['l'], s: ['l'], e: ['r'] }
/** A width (`border`, `border-2`, `border-b`, `border-x-0`, `border-[1px]`), not a colour (`border-[#fff]`, `border-[var(--x)]`). */
const WIDTH = /^border(?:-([xytrblse]))?(?:-(\d+|\[(?:length:[^\]]+|[\d.][^\]]*)\]))?$/
const STYLE = /^border-(solid|dashed|dotted|double|hidden|none)$/
const DIVIDE_WIDTH = /^divide-([xy])(?:-(\d+|\[[^\]]+\]))?$/
const DIVIDE_STYLE = /^divide-(solid|dashed|dotted|double|none)$/
const DRAWS = /^(solid|dashed|dotted|double)$/
const ZERO = /^(0|\[(length:)?0(px)?\])$/
const INLINE_STYLE_WORD = /\b(solid|dashed|dotted|double|groove|ridge|inset|outset|none|hidden)\b/

/** What one class string sets. `covered` and `axes` count only the utilities without a variant. */
interface ClassSet {
  widths: Set<Side>
  covered: Set<Side>
  style: boolean
  draws: boolean
  divideWidth: boolean
  axes: Set<string>
  divideStyle: boolean
  divideDraws: boolean
}

function classSet(text: string): ClassSet {
  const set: ClassSet = { widths: new Set(), covered: new Set(), style: false, draws: false, divideWidth: false, axes: new Set(), divideStyle: false, divideDraws: false }
  for (const token of text.split(/\s+/)) {
    // A token that touches a `${...}` is completed at runtime.
    if (token === '' || token.includes('\u0000')) continue
    let depth = 0
    let cut = 0
    for (let i = 0; i < token.length; i++) {
      if (token[i] === '[') depth++
      else if (token[i] === ']') depth--
      else if (token[i] === ':' && depth === 0) cut = i + 1
    }
    const name = token.slice(cut).replace(/^!/, '')
    const variant = cut > 0
    let m: RegExpExecArray | null
    if ((m = WIDTH.exec(name))) {
      const sides = SIDES_OF[m[1] ?? '']
      if (!variant) sides.forEach((side) => set.covered.add(side))
      if (!ZERO.test(m[2] ?? '1')) sides.forEach((side) => set.widths.add(side))
    } else if ((m = STYLE.exec(name))) {
      set.style = true
      set.draws ||= DRAWS.test(m[1])
    } else if ((m = DIVIDE_WIDTH.exec(name))) {
      if (!variant) set.axes.add(m[1])
      set.divideWidth ||= !ZERO.test(m[2] ?? '1')
    } else if ((m = DIVIDE_STYLE.exec(name))) {
      set.divideStyle = true
      set.divideDraws ||= DRAWS.test(m[1])
    }
  }
  return set
}

/** The sides an inline `style` object gives a border style, and the sides it gives a width (a shorthand gives both). */
interface InlineBorders { styled: Set<Side>; covered: Set<Side>; widthOnly: Set<Side>; problems: string[] }

function inlineBorders(style: ts.Expression | undefined): InlineBorders {
  const inline: InlineBorders = { styled: new Set(), covered: new Set(), widthOnly: new Set(), problems: [] }
  if (!style || !ts.isObjectLiteralExpression(style)) return inline
  for (const property of style.properties) {
    if (!ts.isPropertyAssignment(property) || !(ts.isIdentifier(property.name) || ts.isStringLiteral(property.name))) continue
    const m = /^border(Top|Right|Bottom|Left|Inline|Block)?(Style|Width|Color|Radius)?$/.exec(property.name.text)
    if (!m || m[2] === 'Color' || m[2] === 'Radius') continue
    const sides: Side[] = !m[1] ? ALL_SIDES : m[1] === 'Inline' ? ['l', 'r'] : m[1] === 'Block' ? ['t', 'b'] : [m[1][0].toLowerCase() as Side]
    const value = property.initializer
    const text = ts.isStringLiteral(value) || ts.isNoSubstitutionTemplateLiteral(value) ? value.text
      : ts.isTemplateExpression(value) ? [value.head.text, ...value.templateSpans.map((span) => span.literal.text)].join(' ')
        : null
    if (m[2] === 'Style') {
      sides.forEach((side) => inline.styled.add(side))
    } else if (m[2] === 'Width') {
      sides.forEach((side) => { inline.covered.add(side); inline.widthOnly.add(side) })
    } else {
      // A shorthand sets the width and the style of its sides: one without a style word resets them to none.
      sides.forEach((side) => { inline.styled.add(side); inline.covered.add(side) })
      if (text !== null && !INLINE_STYLE_WORD.test(text) && !/^\s*0\s*$/.test(text)) {
        inline.problems.push(`style ${property.name.text}: '${text}' has no border style, so the border is not drawn`)
      }
    }
  }
  return inline
}

/** Why the class pieces of one attribute (and the element's inline style) draw a border wrong, if they do. */
function borderProblems(pieces: Array<{ text: string; always: boolean }>, inline: InlineBorders = inlineBorders(undefined)): string[] {
  const sets = pieces.map((piece) => ({ ...piece, set: classSet(piece.text) }))
  const always = sets.filter((piece) => piece.always).map((piece) => piece.set)
  const alwaysStyle = always.some((set) => set.style)
  const alwaysDivideStyle = always.some((set) => set.divideStyle)
  const covered = new Set([...always.flatMap((set) => [...set.covered]), ...inline.covered])
  const axes = new Set(always.flatMap((set) => [...set.axes]))
  const problems = [...inline.problems]
  for (const { text, set } of sets) {
    const shown = `"${text.replace(/\u0000/g, '${…}')}"`
    const unstyled = [...set.widths].filter((side) => !set.style && !alwaysStyle && !inline.styled.has(side))
    if (unstyled.length > 0) problems.push(`${shown}: a border width with no border style is not drawn (side ${unstyled.join('')})`)
    const medium = set.draws ? ALL_SIDES.filter((side) => !set.covered.has(side) && !covered.has(side)) : []
    if (medium.length > 0) problems.push(`${shown}: a border style with no width on side ${medium.join('')} draws a 3px border there (add border-0)`)
    if (set.divideWidth && !set.divideStyle && !alwaysDivideStyle) problems.push(`${shown}: a divide width with no divide style is not drawn`)
    const missing = set.divideDraws ? ['x', 'y'].filter((axis) => !set.axes.has(axis) && !axes.has(axis)) : []
    if (missing.length > 0) problems.push(`${shown}: a divide style with no divide-${missing.join('/divide-')} width draws 3px borders on the other sides of the children`)
  }
  const classStyled = sets.some((piece) => piece.always && piece.set.style)
  const inlineUnstyled = [...inline.widthOnly].filter((side) => !classStyled && !inline.styled.has(side))
  if (inlineUnstyled.length > 0) problems.push(`style: a border width with no border style is not drawn (side ${inlineUnstyled.join('')})`)
  return problems
}

/** A string a class check must leave alone: an attribute value that is not a class, a key, a type, a comparison, a translation. */
function notAClassString(node: ts.Node): boolean {
  const parent = node.parent
  const attribute = ts.isJsxExpression(parent) ? parent.parent : parent
  return (ts.isJsxAttribute(attribute) && !/className$/i.test(attribute.name.getText()))
    || ((ts.isPropertyAssignment(parent) || ts.isPropertyAccessExpression(parent)) && parent.name === node)
    || ts.isLiteralTypeNode(parent)
    || ts.isImportDeclaration(parent) || ts.isExportDeclaration(parent) || ts.isExternalModuleReference(parent)
    || ts.isCaseClause(parent)
    || (ts.isBinaryExpression(parent) && [ts.SyntaxKind.EqualsEqualsEqualsToken, ts.SyntaxKind.ExclamationEqualsEqualsToken, ts.SyntaxKind.EqualsEqualsToken, ts.SyntaxKind.ExclamationEqualsToken].includes(parent.operatorToken.kind))
    || (ts.isCallExpression(parent) && ts.isIdentifier(parent.expression) && /^t([A-Z]\w*)?$/.test(parent.expression.text))
    || (ts.isCallExpression(parent) && ts.isPropertyAccessExpression(parent.expression) && ['t', 'getPropertyValue', 'setProperty', 'removeProperty'].includes(parent.expression.name.text))
}

/** Every border problem of a source, `path:line` first. */
function sourceBorderProblems(fileName: string, source: string): string[] {
  const file = parse(fileName, source)
  const line = (node: ts.Node) => file.getLineAndCharacterOfPosition(node.getStart(file)).line + 1
  const constants = new Map<string, ts.Expression>()
  const collect = (node: ts.Node): void => {
    if (ts.isVariableDeclaration(node) && ts.isIdentifier(node.name) && node.initializer) constants.set(node.name.text, node.initializer)
    ts.forEachChild(node, collect)
  }
  collect(file)

  const consumed = new Set<ts.Node>()
  const piecesOf = (expr: ts.Expression | undefined, always: boolean, seen: Set<string>, out: Array<{ text: string; always: boolean }>): void => {
    if (!expr) return
    if (ts.isStringLiteral(expr) || ts.isNoSubstitutionTemplateLiteral(expr)) {
      consumed.add(expr)
      out.push({ text: expr.text, always })
    } else if (ts.isTemplateExpression(expr)) {
      consumed.add(expr)
      out.push({ text: [expr.head.text, ...expr.templateSpans.map((span) => span.literal.text)].join('\u0000'), always })
      expr.templateSpans.forEach((span) => piecesOf(span.expression, always, seen, out))
    } else if (ts.isConditionalExpression(expr)) {
      piecesOf(expr.whenTrue, false, seen, out)
      piecesOf(expr.whenFalse, false, seen, out)
    } else if (ts.isBinaryExpression(expr)) {
      const op = expr.operatorToken.kind
      if (op === ts.SyntaxKind.PlusToken) {
        piecesOf(expr.left, always, seen, out)
        piecesOf(expr.right, always, seen, out)
      } else if (op === ts.SyntaxKind.AmpersandAmpersandToken) {
        piecesOf(expr.right, false, seen, out)
      } else if (op === ts.SyntaxKind.BarBarToken || op === ts.SyntaxKind.QuestionQuestionToken) {
        piecesOf(expr.left, false, seen, out)
        piecesOf(expr.right, false, seen, out)
      }
    } else if (ts.isParenthesizedExpression(expr) || ts.isAsExpression(expr) || ts.isNonNullExpression(expr) || ts.isSatisfiesExpression(expr)) {
      piecesOf(expr.expression, always, seen, out)
    } else if (ts.isArrayLiteralExpression(expr)) {
      expr.elements.forEach((element) => piecesOf(ts.isSpreadElement(element) ? element.expression : element, always && !ts.isSpreadElement(element), seen, out))
    } else if (ts.isCallExpression(expr)) {
      // `[...].join(' ')`, `.filter(Boolean)`, `.trim()`: the receiver's pieces; a helper's arguments may be dropped.
      if (ts.isPropertyAccessExpression(expr.expression)) piecesOf(expr.expression.expression, always, seen, out)
      expr.arguments.forEach((argument) => piecesOf(argument, false, seen, out))
    } else if (ts.isPropertyAccessExpression(expr) || ts.isElementAccessExpression(expr)) {
      piecesOf(expr.expression, false, seen, out)
    } else if (ts.isObjectLiteralExpression(expr)) {
      expr.properties.forEach((property) => { if (ts.isPropertyAssignment(property)) piecesOf(property.initializer, false, seen, out) })
    } else if (ts.isIdentifier(expr) && constants.has(expr.text) && !seen.has(expr.text)) {
      piecesOf(constants.get(expr.text), always, new Set([...seen, expr.text]), out)
    }
  }

  const problems: string[] = []
  const visitElements = (node: ts.Node): void => {
    if (ts.isJsxOpeningElement(node) || ts.isJsxSelfClosingElement(node)) {
      const attributes = node.attributes.properties.filter(ts.isJsxAttribute)
      const valueOf = (attribute: ts.JsxAttribute) => attribute.initializer && ts.isJsxExpression(attribute.initializer) ? attribute.initializer.expression : attribute.initializer as ts.Expression | undefined
      const style = attributes.find((attribute) => attribute.name.getText(file) === 'style')
      const classAttributes = attributes.filter((attribute) => /className$/i.test(attribute.name.getText(file)))
      const inline = inlineBorders(style ? valueOf(style) : undefined)
      for (const attribute of classAttributes) {
        const pieces: Array<{ text: string; always: boolean }> = []
        piecesOf(valueOf(attribute), true, new Set(), pieces)
        const own = attribute.name.getText(file) === 'className'
        borderProblems(pieces, own ? inline : undefined).forEach((problem) => problems.push(`${fileName}:${line(attribute)} <${node.tagName.getText(file)}> ${problem}`))
      }
      if (!classAttributes.some((attribute) => attribute.name.getText(file) === 'className')) {
        borderProblems([], inline).forEach((problem) => problems.push(`${fileName}:${line(node)} <${node.tagName.getText(file)}> ${problem}`))
      }
    }
    ts.forEachChild(node, visitElements)
  }
  visitElements(file)

  // Class strings no attribute above reached: constants passed as props, lookup tables, returns.
  const visitStrings = (node: ts.Node): void => {
    if ((ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node) || ts.isTemplateExpression(node)) && !consumed.has(node) && !notAClassString(node)) {
      const text = ts.isTemplateExpression(node) ? [node.head.text, ...node.templateSpans.map((span) => span.literal.text)].join('\u0000') : node.text
      borderProblems([{ text, always: true }]).forEach((problem) => problems.push(`${fileName}:${line(node)} ${problem}`))
    }
    ts.forEachChild(node, visitStrings)
  }
  visitStrings(file)
  return problems
}

// ------------------------------------------------------------------ tests

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

  it('sees the names written whole, and not a name completed at runtime or a prefix', () => {
    const source = [
      "const a = getComputedStyle(el).getPropertyValue('--martis-by-get-property-value')",
      "root.style.setProperty('--martis-by-set-property', c)",
      "root.style.removeProperty('--martis-by-remove-property')",
      "const b = cssVar('--martis-by-css-var', '#fff')",
      "const c = { '--martis-by-inline-key': '2' } as React.CSSProperties",
      "const d = getPropertyValue(`--martis-avatar-${slot}`)",
      "const e = '--martis-avatar-' + slot",
      "// root.style.setProperty('--martis-in-a-comment', c)",
    ].join('\n')

    expect(reads('probe.ts', source).sort()).toEqual([
      '--martis-by-css-var', '--martis-by-get-property-value', '--martis-by-inline-key', '--martis-by-remove-property', '--martis-by-set-property',
    ])
  })
})

describe('the border check', () => {
  // One probe per route a border can end up undrawn or drawn 3px wide; each must be refused.
  const refused: Record<string, string> = {
    'a width with no style': '<div className="rounded-lg border shadow-lg" />',
    'a width on one side with no style': '<div className="border-b px-6" style={{ borderColor: "var(--martis-border)" }} />',
    'a one-sided width with a style but the other sides at medium': '<div className="border-b border-solid" />',
    'a style with no width at all': '<div className="border-solid" />',
    'a style only under a condition': '<div className={`rounded border ${open ? "border-solid" : ""}`} />',
    'a style only in a dropped array piece': "<div className={['rounded border', open && 'border-solid'].join(' ')} />",
    'a width only under a variant, the style everywhere': '<div className="md:border border-solid" />',
    'a class constant passed as a prop': 'const card = "rounded-lg border"',
    'a lookup table value': "const tone = { quiet: 'border-t text-sm' }",
    'a divide width with no style': '<ul className="divide-y" />',
    'a divide style with one axis only': '<ul className="divide-y divide-solid" />',
    'an inline shorthand with no style': "<div style={{ borderTop: '1px var(--martis-border)' }} />",
    'an inline colour-only shorthand': "<div style={{ border: 'var(--martis-border)' }} />",
    'an inline width with no style': '<div style={{ borderWidth: 1 }} />',
    'a class width whose inline style covers one side only': "<div className=\"rounded-lg border\" style={{ borderLeft: '3px solid red' }} />",
    'a stub component': '<input className="w-full rounded-md border px-3 py-2 text-sm" />',
  }
  // The nearest cases on the other side of the rule; each must pass.
  const accepted: Record<string, string> = {
    'a zero width': '<div className="border-0" />',
    'zero on one side, under a variant too': '<div className="border-t-0 last:border-b-0" />',
    'colours': '<div className="border-martis-border border-[#fff] border-[color:var(--x)] border-[var(--x)] border-transparent border-t-transparent" />',
    'table utilities': '<table className="border-collapse border-separate border-spacing-2" />',
    'a width with its style': '<div className="rounded-lg border border-solid shadow-lg" />',
    'one side with the others zeroed': '<div className="border-0 border-b border-solid" />',
    'both axes': '<div className="border-x border-y border-dashed" />',
    'a style after an interpolation': '<div className={`rounded border ${tone} border-solid`} />',
    'a style in an array piece always kept': "<div className={['rounded border', 'border-solid', open && 'shadow'].join(' ')} />",
    'a width and its style under the same condition': '<div className={open ? "border-0 border-b border-solid" : "border-0"} />',
    'a class constant with its style': 'const card = "rounded-lg border border-solid"',
    'a style that draws nothing': '<div className="border-none" />',
    'a spinning ring': '<div className="animate-spin rounded-full border-2 border-solid border-current border-t-transparent" />',
    'a divider list': '<ul className="divide-y divide-x-0 divide-solid" />',
    'a width completed at runtime': '<div className={`border-${side}`} />',
    'display text and non-class strings': [
      '<p title="border">Add a border</p>',
      "const label = t('border_hint', 'Draw a border')",
      "type Look = 'border' | 'fill'",
      "if (look === 'border') {}",
    ].join('\n'),
    'inline shorthands with a style, or none': "<div className=\"rounded-lg border border-solid\" style={{ borderLeft: `3px solid ${accent}`, borderTop: 'none', borderBottom: 0 }} />",
    'an inline width with a class style': '<div className="border-solid" style={{ borderWidth: 1 }} />',
  }

  it('refuses every route to an undrawn or 3px border', () => {
    for (const [route, source] of Object.entries(refused)) {
      expect(sourceBorderProblems('probe.tsx', source), route).not.toEqual([])
    }
  })

  it('accepts the complete forms and every other border utility', () => {
    for (const [route, source] of Object.entries(accepted)) {
      expect(sourceBorderProblems('probe.tsx', source), route).toEqual([])
    }
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
    expect(files.some((path) => path.endsWith('.tsx.stub'))).toBe(true)
    expect([...new Set(undefinedReads)]).toEqual([])
  })

  it('allow only the undefined variables docs/theming.md names as not counted', () => {
    const paragraph = themingDoc.split('\n').find((line) => line.startsWith('Not counted:')) ?? ''
    const named = [...paragraph.matchAll(new RegExp(`\`(${NAME.source})\``, 'g'))].map((m) => m[1])
    const defined = definedVariables()

    expect(paragraph).not.toBe('')
    expect(ALLOWED_UNDEFINED.filter((name) => !named.includes(name))).toEqual([])
    expect(named.filter((name) => !defined.has(name) && !ALLOWED_UNDEFINED.includes(name))).toEqual([])
  })

  it('draw every border they give a width, and no side they leave without one', () => {
    expect(files.flatMap((path) => sourceBorderProblems(path, SOURCES[path]))).toEqual([])
  })
})
