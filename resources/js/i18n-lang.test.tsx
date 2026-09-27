import { describe, it, expect, afterEach } from 'vitest'
import { applyDocumentDirection } from '@/lib/i18n'

afterEach(() => {
  document.documentElement.removeAttribute('lang')
  document.documentElement.removeAttribute('dir')
})

describe('applyDocumentDirection lang attribute', () => {
  it('writes a BCP 47 tag for an underscore locale', () => {
    applyDocumentDirection('pt_PT')

    expect(document.documentElement.getAttribute('lang')).toBe('pt-PT')
  })

  it('keeps a code Intl cannot canonicalise as it is', () => {
    applyDocumentDirection('xx_!!')

    expect(document.documentElement.getAttribute('lang')).toBe('xx_!!')
  })
})
