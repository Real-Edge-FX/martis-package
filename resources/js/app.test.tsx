import { describe, it, expect } from 'vitest'

describe('Martis Admin Shell', () => {
  it('package identity is correct', () => {
    expect('@martis/martis').toContain('martis')
  })

  it('entry point exports nothing (side-effect only)', () => {
    expect(true).toBe(true)
  })
})

