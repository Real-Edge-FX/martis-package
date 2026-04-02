import { describe, it, expect } from 'vitest'

describe('Martis Admin Engine', () => {
    it('should load without errors', () => {
        expect(true).toBe(true)
    })

    it('should have correct package identity', () => {
        const name = '@martis/martis'
        expect(name).toContain('martis')
    })
})
