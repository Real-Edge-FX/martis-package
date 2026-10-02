import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import path from 'node:path'

/*
 * Every `martis-*` class a docs example puts in a `className` (or `class`)
 * attribute exists in resources/css/martis.css. A reader copies the example
 * into an extension, and a class the stylesheet does not define renders
 * unstyled: the "Custom modal responses" recipe used `.martis-modal`, so the
 * token it shows landed below the 100vh shell, out of sight.
 *
 * martis.css is read from disk (Vitest runs from the package root): Vitest
 * does not load .css sources as text. The package has no Node types, so
 * tsconfig.json leaves this file out, as it does the test kit's Node test.
 * Only static values are read (a quoted string, or one in braces); a class
 * built at runtime is not checked.
 */

// docs/superpowers/ holds local, git-ignored working notes (plans, specs),
// not published pages: a checkout that has them must not fail on them.
const docs = import.meta.glob(['../../docs/**/*.md', '!../../docs/superpowers/**'], { query: '?raw', import: 'default', eager: true }) as Record<string, string>
const css = readFileSync(path.resolve(process.cwd(), 'resources/css/martis.css'), 'utf8')

/** The class selectors martis.css defines, comments left out. */
const defined = new Set([...css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/\.(martis-[A-Za-z0-9_-]+)/g)].map((match) => match[1]))

/** `page: class` for every martis-* class used in an attribute of a fenced code block. */
function usedClasses(): string[] {
  const used = new Set<string>()
  for (const [file, markdown] of Object.entries(docs)) {
    const page = file.replace('../../docs/', '')
    for (const [, block] of markdown.matchAll(/^ {0,3}(?:```|~~~)[^\n]*\n([\s\S]*?)^ {0,3}(?:```|~~~)/gm)) {
      for (const match of block.matchAll(/\bclass(?:Name)?=(?:"([^"]*)"|'([^']*)'|\{\s*(?:"([^"]*)"|'([^']*)'|`([^`$]*)`)\s*\})/g)) {
        const value = match.slice(1).find((group) => group !== undefined) ?? ''
        for (const name of value.split(/\s+/)) {
          if (name.startsWith('martis-')) used.add(`${page}: ${name}`)
        }
      }
    }
  }
  return [...used].sort()
}

describe('the docs examples', () => {
  it('read the docs and the stylesheet', () => {
    expect(Object.keys(docs).length).toBeGreaterThan(30)
    expect(defined.has('martis-modal-scrim')).toBe(true)
    expect(usedClasses().length).toBeGreaterThan(10)
  })

  it('use only martis-* classes martis.css defines', () => {
    const missing = usedClasses().filter((entry) => !defined.has(entry.slice(entry.indexOf(': ') + 2)))

    expect(missing).toEqual([])
  })
})
