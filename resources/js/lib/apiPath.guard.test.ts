import { describe, expect, it } from 'vitest'

/*
 * Client-side path traversal guard (F007, F011, F012, F015, F016, F066,
 * F096, F099).
 *
 * Every panel API path built from a value (a route param, a query param, a
 * record id, a relationship name, a field attribute, a Tool, Action or Lens
 * key) goes through the `apiPath` tag, which encodes each interpolated value
 * as one path segment. A template literal that holds `/api/` text and
 * interpolates, without that tag, is a path built the old way: the value can
 * carry `../` or `?` and rewrite the request.
 *
 * This scans the SPA's source (the tests aside) for exactly that, so a path
 * added later cannot reopen the weakness without failing here. To build a
 * path from a value, write:
 *
 *   api.get(apiPath`/api/resources/${resource}/${id}`)
 *
 * and append an already built query string with `withQuery()`.
 */

/**
 * The SPA's source, tests aside, read as text by Vite (the package has no Node
 * types, so no `fs`): path from the package root -> source.
 */
const SOURCES = import.meta.glob<string>(
  ['/resources/js/**/*.{ts,tsx}', '!/resources/js/**/*.test.{ts,tsx}'],
  { query: '?raw', import: 'default', eager: true },
)
const ALLOWED_TAGS = new Set(['apiPath'])

interface Template {
  /** The identifier right before the opening backtick (a tag), or ''. */
  tag: string
  /** The literal text parts, without the `${...}` expressions. */
  quasis: string[]
  /** Whether at least one `${...}` is interpolated. */
  interpolates: boolean
  line: number
}

/**
 * Every template literal of `source`, nested ones included. A small scanner
 * that skips comments, quotes and the `${...}` expressions, so a backtick in
 * a comment or a string never opens a template.
 */
function templatesOf(source: string): Template[] {
  const found: Template[] = []
  let i = 0

  const lineAt = (index: number): number => source.slice(0, index).split('\n').length

  function skipLine(): void {
    while (i < source.length && source[i] !== '\n') i++
  }

  function skipBlockComment(): void {
    const end = source.indexOf('*/', i + 2)
    i = end === -1 ? source.length : end + 2
  }

  function skipQuoted(quote: string): void {
    i++
    // A quoted string ends on its line: an apostrophe in JSX text must not
    // swallow the code that follows it.
    while (i < source.length && source[i] !== quote && source[i] !== '\n') {
      if (source[i] === '\\') i++
      i++
    }
    i++
  }

  function readTemplate(): void {
    const start = i
    let k = start - 1
    while (k >= 0 && /[\w$.]/.test(source[k] ?? '')) k--
    const tag = source.slice(k + 1, start)
    const template: Template = { tag, quasis: [''], interpolates: false, line: lineAt(start) }
    found.push(template)
    i++
    while (i < source.length && source[i] !== '`') {
      if (source[i] === '\\') {
        template.quasis[template.quasis.length - 1] += source.slice(i, i + 2)
        i += 2
      } else if (source[i] === '$' && source[i + 1] === '{') {
        template.interpolates = true
        template.quasis.push('')
        i += 2
        readCode('}')
        i++
      } else {
        template.quasis[template.quasis.length - 1] += source[i]
        i++
      }
    }
    i++
  }

  /** Scan code until the `close` brace of the expression (or the end of the file). */
  function readCode(close: string | null): void {
    let depth = 0
    while (i < source.length) {
      const c = source[i]
      const next = source[i + 1]
      if (c === '/' && next === '/') skipLine()
      else if (c === '/' && next === '*') skipBlockComment()
      else if (c === '\'' || c === '"') skipQuoted(c)
      else if (c === '`') readTemplate()
      else if (c === '{') {
        depth++
        i++
      } else if (c === '}') {
        if (close !== null && depth === 0) return
        depth--
        i++
      } else i++
    }
  }

  readCode(null)
  return found
}

/**
 * Whether a value is interpolated into the API path or its query: some `${...}`
 * follows a literal part that holds `/api/`. A base put in front
 * (`${BASE_PATH}/api/attachments/upload`) interpolates no value into the path.
 */
function interpolatesIntoApiPath(template: Template): boolean {
  return template.quasis.some((text, index) => text.includes('/api/') && index < template.quasis.length - 1)
}

const files = Object.keys(SOURCES)

describe('API paths built from values go through apiPath', () => {
  it('scans the SPA source', () => {
    expect(files.length).toBeGreaterThan(100)
    expect(files).toContain('/resources/js/lib/api.ts')
    expect(files.some((file) => file.includes('.test.'))).toBe(false)
  })

  it('finds a template that interpolates into an API path (self-check of the scanner)', () => {
    const bad = templatesOf('const url = `/api/resources/${resource}/${id}`; // `/api/x/${y}`')
    expect(bad).toHaveLength(1)
    expect(bad[0]?.tag).toBe('')
    expect(bad[0]?.interpolates).toBe(true)

    const nested = templatesOf('const url = apiPath`/api/resources/${a}${b ? `/lenses/${b}` : \'\'}/actions`')
    expect(nested.map((t) => t.tag)).toEqual(['apiPath', ''])
  })

  it('builds no API path from an interpolated template that skips the tag', () => {
    const violations = files.flatMap((file) =>
      templatesOf(SOURCES[file] ?? '')
        .filter((template) => interpolatesIntoApiPath(template))
        .filter((template) => !ALLOWED_TAGS.has(template.tag))
        .map((template) => `${file}:${template.line}: ${template.tag}\`${template.quasis.join('${…}')}\``),
    )

    expect(violations, 'build an API path with apiPath`...` so interpolated values stay one segment').toEqual([])
  })

  it('joins no value to an API path by concatenation', () => {
    const concatenations = files.flatMap((file) =>
      (SOURCES[file] ?? '').split('\n')
        .map((text, index) => ({ text, line: index + 1 }))
        .filter(({ text }) => !/^\s*(\/\/|\*|\/\*)/.test(text))
        .filter(({ text }) => /['"]\/api\/[^'"]*['"]\s*\+/.test(text))
        .map(({ text, line }) => `${file}:${line}: ${text.trim()}`),
    )

    expect(concatenations).toEqual([])
  })
})
