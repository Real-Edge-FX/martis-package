import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const css = readFileSync(resolve(__dirname, '../css/martis.css'), 'utf8')

describe('full-width detail values', () => {
  it('reset the default dd indent so the value aligns with the label', () => {
    expect(css).toMatch(
      /\.martis-detail-row\.is-full-width \.martis-detail-value,\s*\.martis-section-row\.is-full-width > dd\s*\{[^}]*margin:\s*0/,
    )
  })
})
